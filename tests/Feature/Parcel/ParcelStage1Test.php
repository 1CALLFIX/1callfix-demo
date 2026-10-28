<?php

namespace Tests\Feature\Parcel;

use App\Actions\AcceptParcelOfferAction;
use App\Actions\CreateParcelOrderAction;
use App\Actions\MarkParcelArrivedAtPickupAction;
use App\Actions\MarkParcelDeliveredAction;
use App\Actions\MarkParcelPickedUpAction;
use App\Contracts\SmsAdapter;
use App\Exceptions\ParcelQuoteException;
use App\Jobs\ParcelDispatchJob;
use App\Livewire\Customer\Parcel\Create;
use App\Livewire\Customer\Parcel\Show;
use App\Livewire\ParcelOrders\Dashboard;
use App\Livewire\Rider\Dashboard as RiderDashboard;
use App\Models\Commission;
use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\FieldWorkerCashDue;
use App\Models\ParcelOrder;
use App\Models\Setting;
use App\Notifications\ParcelOrderStatusNotification;
use App\Services\DispatchService;
use App\Services\TimezoneResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\Support\CapturingSmsAdapter;
use Tests\TestCase;

/**
 * Parcel Stage 1 (owner go-ahead 2026-10-25, after the Borzo / Shiprocket study): Now | Schedule, the waiting
 * charge at pickup, contents + restricted items, the rider's name and phone in the recipient SMS, the vehicle
 * field, and the franchise Parcel Dashboard.
 */
class ParcelStage1Test extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;

    private function world(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);
        $rider = $this->makeParcelRiderIn($franchise, $zone);

        return compact('franchise', 'zone', 'customer', 'pickup', 'dropoff', 'rider');
    }

    private function create(array $w, array $extra = []): ParcelOrder
    {
        return app(CreateParcelOrderAction::class)->execute(array_merge([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id,
            'recipient_name' => 'Ravi', 'recipient_phone' => '9876543210',
            'payment_method' => 'cash', 'restricted_items_confirmed' => true,
        ], $extra));
    }

    private function enableSchedule(string $fare = '30'): void
    {
        Setting::set('parcel.schedule_base_fare', $fare);
    }

    private function enableWaiting(): void
    {
        Setting::set('parcel.waiting_free_minutes', '10');
        Setting::set('parcel.waiting_block_minutes', '15');
        Setting::set('parcel.waiting_charge_per_block', '20');
    }

    private function assigned(array $w, ParcelOrder $order): ParcelOrder
    {
        $order->update(['status' => 'assigned', 'assigned_worker_id' => $w['rider']->id, 'pickup_otp' => '1111', 'delivery_otp' => '2222']);

        return $order->fresh();
    }

    // ═══════════════════════ restricted items + contents ═══════════════════════

    public function test_a_parcel_cannot_be_booked_without_the_restricted_items_confirmation(): void
    {
        $w = $this->world();

        $this->expectExceptionMessage('Confirm the parcel has none of the restricted items.');
        $this->create($w, ['restricted_items_confirmed' => false]);
    }

    public function test_the_confirmation_time_and_contents_are_recorded(): void
    {
        $w = $this->world();

        $order = $this->create($w, ['package_contents' => 'documents']);

        $this->assertNotNull($order->restricted_items_confirmed_at);
        $this->assertSame('documents', $order->package_contents);
        $this->assertSame('two_wheeler', $order->vehicle_type);
    }

    public function test_an_unknown_contents_value_is_refused(): void
    {
        $w = $this->world();

        $this->expectExceptionMessage('Choose what is inside the parcel from the list.');
        $this->create($w, ['package_contents' => 'gold-bars']);
    }

    public function test_the_api_and_the_web_page_both_require_the_confirmation(): void
    {
        $w = $this->world();

        $this->actingAs($w['customer'], 'sanctum')
            ->postJson('/api/parcel-orders', ['pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id, 'recipient_phone' => '9876543210'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['restricted_items_confirmed']);

        Livewire::actingAs($w['customer'])->test(Create::class)
            ->set('dropMode', 'saved')->set('dropAddressId', $w['dropoff']->id)
            ->set('recipientPhone', '9876543210')->set('paymentMethod', 'cash')
            ->call('submit')
            ->assertHasErrors(['restrictedConfirmed']);

        $this->assertSame(0, ParcelOrder::count());
    }

    // ═══════════════════════ Now | Schedule ═══════════════════════

    public function test_schedule_is_refused_where_its_base_fare_is_not_set(): void
    {
        $w = $this->world();

        $this->expectException(ParcelQuoteException::class);
        $this->create($w, ['delivery_type' => 'scheduled', 'scheduled_at' => now()->addHours(3)]);
    }

    public function test_a_scheduled_parcel_uses_its_own_base_fare_and_is_held_from_riders(): void
    {
        Queue::fake();
        $w = $this->world();
        $this->enableSchedule('30'); // base fare is 40 (fixture); trip is under the 2 km included

        $order = $this->create($w, ['delivery_type' => 'scheduled', 'scheduled_at' => now()->addHours(3)]);

        $this->assertTrue($order->isScheduled());
        $this->assertSame(30.0, (float) $order->price_quoted);
        $this->assertStringContainsString('scheduled', $order->price_breakdown[0]['label']);
        $this->assertNull($order->fresh()->dispatch_released_at);
        Queue::assertNotPushed(ParcelDispatchJob::class);
    }

    public function test_a_scheduled_pickup_time_must_be_inside_the_admin_window(): void
    {
        $w = $this->world();
        $this->enableSchedule();

        try {
            $this->create($w, ['delivery_type' => 'scheduled', 'scheduled_at' => now()->addMinutes(20)]);
            $this->fail('A pickup 20 minutes ahead must be refused (default lead 60).');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('at least 60 minutes', $e->getMessage());
        }

        $this->expectExceptionMessage('You can schedule up to 7 days ahead.');
        $this->create($w, ['delivery_type' => 'scheduled', 'scheduled_at' => now()->addDays(8)]);
    }

    public function test_the_web_page_time_is_read_in_the_platform_timezone_and_stored_in_utc(): void
    {
        $w = $this->world();
        $this->enableSchedule();
        $tz = app(TimezoneResolver::class)->platformTimezone();
        $local = now($tz)->addDay()->setTime(10, 30);

        $order = $this->create($w, ['delivery_type' => 'scheduled', 'scheduled_at' => $local->format('Y-m-d\TH:i')]);

        $this->assertTrue($order->scheduled_at->equalTo($local->copy()->utc()));
    }

    public function test_the_release_sweep_offers_a_due_scheduled_parcel_exactly_once(): void
    {
        Queue::fake();
        $w = $this->world();
        $this->enableSchedule();
        $order = $this->create($w, ['delivery_type' => 'scheduled', 'scheduled_at' => now()->addHours(2)]);

        $this->artisan('parcel:release-scheduled')->assertSuccessful();
        Queue::assertNotPushed(ParcelDispatchJob::class); // 2 h ahead, lead is 30 min

        $this->travel(95)->minutes(); // pickup now 25 min away
        $this->artisan('parcel:release-scheduled')->assertSuccessful();
        $this->artisan('parcel:release-scheduled')->assertSuccessful();

        Queue::assertPushed(ParcelDispatchJob::class, 1);
        $this->assertNotNull($order->fresh()->dispatch_released_at);
    }

    public function test_an_unpaid_online_scheduled_parcel_is_never_released(): void
    {
        Queue::fake();
        $w = $this->world();
        $this->enableSchedule();
        $this->create($w, ['payment_method' => 'online', 'delivery_type' => 'scheduled', 'scheduled_at' => now()->addMinutes(70)]);

        $this->travel(50)->minutes();
        $this->artisan('parcel:release-scheduled')->assertSuccessful();

        Queue::assertNotPushed(ParcelDispatchJob::class);
    }

    public function test_paying_online_for_a_scheduled_parcel_does_not_start_the_search_early(): void
    {
        Queue::fake();
        $w = $this->world();
        $this->enableSchedule();
        $order = $this->create($w, ['payment_method' => 'online', 'delivery_type' => 'scheduled', 'scheduled_at' => now()->addHours(5)]);

        $order->update(['payment_status' => 'paid']);
        CreateParcelOrderAction::dispatchAndNotify($order->fresh());

        Queue::assertNotPushed(ParcelDispatchJob::class);
        $this->assertNull($order->fresh()->dispatch_released_at);
    }

    public function test_the_dispatch_job_refuses_a_scheduled_parcel_that_is_still_held(): void
    {
        $w = $this->world();
        $this->enableSchedule();
        Queue::fake();
        $order = $this->create($w, ['delivery_type' => 'scheduled', 'scheduled_at' => now()->addHours(5)]);

        (new ParcelDispatchJob($order->id))->handle(app(DispatchService::class));

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, DispatchAttempt::count());
    }

    public function test_a_now_parcel_is_released_at_once_and_marked(): void
    {
        Queue::fake();
        $w = $this->world();

        $order = $this->create($w);

        Queue::assertPushed(ParcelDispatchJob::class, 1);
        $this->assertNotNull($order->fresh()->dispatch_released_at);
        $this->assertSame('now', $order->delivery_type);
    }

    // ═══════════════════════ waiting charge ═══════════════════════

    public function test_arrival_is_refused_when_the_rider_is_far_from_the_pickup(): void
    {
        $w = $this->world();
        $order = $this->assigned($w, $this->create($w));

        $this->expectExceptionMessage('km from the pickup point');
        app(MarkParcelArrivedAtPickupAction::class)->execute($order->id, $w['rider'], 1.05, 1.05);
    }

    public function test_arrival_needs_a_location_and_the_assigned_rider(): void
    {
        $w = $this->world();
        $order = $this->assigned($w, $this->create($w));
        $other = $this->makeParcelRiderIn($w['franchise'], $w['zone']);

        try {
            app(MarkParcelArrivedAtPickupAction::class)->execute($order->id, $w['rider'], null, null);
            $this->fail('No location must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("couldn't read your location", $e->getMessage());
        }

        $this->expectExceptionMessage('not assigned to you');
        app(MarkParcelArrivedAtPickupAction::class)->execute($order->id, $other, 1.0, 1.0);
    }

    public function test_arrival_tells_the_sender_and_a_double_tap_never_restarts_the_clock(): void
    {
        Notification::fake();
        $w = $this->world();
        $order = $this->assigned($w, $this->create($w));

        app(MarkParcelArrivedAtPickupAction::class)->execute($order->id, $w['rider'], 1.0005, 1.0005);
        $first = $order->fresh()->arrived_at_pickup_at;
        $this->travel(5)->minutes();
        app(MarkParcelArrivedAtPickupAction::class)->execute($order->id, $w['rider'], 1.0, 1.0);

        $this->assertTrue($first->equalTo($order->fresh()->arrived_at_pickup_at));
        Notification::assertSentToTimes($w['customer'], ParcelOrderStatusNotification::class, 2); // created + arrived
    }

    public function test_waiting_is_charged_per_started_block_after_the_free_time_and_paid_to_the_rider(): void
    {
        $w = $this->world();
        $this->enableWaiting(); // 10 min free, then 20 per started 15 min
        $order = $this->assigned($w, $this->create($w, ['price_quoted' => 100]));

        app(MarkParcelArrivedAtPickupAction::class)->execute($order->id, $w['rider'], 1.0, 1.0);
        $this->travel(34)->minutes(); // 24 min over the free time = 2 started blocks
        app(MarkParcelPickedUpAction::class)->execute($order->id, $w['rider'], '1111');

        $order->refresh();
        $this->assertSame(34, $order->waiting_minutes);
        $this->assertSame(40.0, (float) $order->waiting_charge);

        // The waiting charge is the rider's own money: never in the delivery price, the commission or the cash due.
        app(MarkParcelDeliveredAction::class)->execute($order->id, $w['rider'], '2222');
        $order->refresh();
        $this->assertSame(100.0, (float) $order->price_final);
        $commission = Commission::where('parcel_order_id', $order->id)->firstOrFail();
        $this->assertSame(100.0, round((float) $commission->provider_commission + (float) $commission->franchise_commission + (float) $commission->platform_commission, 2));
        $due = FieldWorkerCashDue::where('parcel_order_id', $order->id)->first();
        $this->assertSame(round((float) $commission->platform_commission + (float) $commission->franchise_commission, 2), round((float) ($due?->amount_owed ?? 0), 2));
    }

    public function test_waiting_inside_the_free_time_costs_nothing(): void
    {
        $w = $this->world();
        $this->enableWaiting();
        $order = $this->assigned($w, $this->create($w));

        app(MarkParcelArrivedAtPickupAction::class)->execute($order->id, $w['rider'], 1.0, 1.0);
        $this->travel(9)->minutes();
        app(MarkParcelPickedUpAction::class)->execute($order->id, $w['rider'], '1111');

        $this->assertSame(9, $order->fresh()->waiting_minutes);
        $this->assertNull($order->fresh()->waiting_charge);
    }

    public function test_no_waiting_charge_while_the_admin_has_not_set_it(): void
    {
        $w = $this->world();
        $order = $this->assigned($w, $this->create($w));

        app(MarkParcelArrivedAtPickupAction::class)->execute($order->id, $w['rider'], 1.0, 1.0);
        $this->travel(60)->minutes();
        app(MarkParcelPickedUpAction::class)->execute($order->id, $w['rider'], '1111');

        $this->assertNull($order->fresh()->waiting_charge);
    }

    public function test_the_rider_screen_shows_the_waiting_charge_to_collect(): void
    {
        $w = $this->world();
        $this->enableWaiting();
        $order = $this->assigned($w, $this->create($w));

        Livewire::actingAs($w['rider']->user)->test(RiderDashboard::class)
            ->assertSee('arrived at pickup')
            ->call('arrived', $order->id, 1.0, 1.0)
            ->assertSet('error', '');

        $this->travel(26)->minutes(); // 16 over = 2 blocks
        Livewire::actingAs($w['rider']->user)->test(RiderDashboard::class)
            ->assertSee('waiting charge from the sender')
            ->assertSee('40.00');
    }

    public function test_the_tracking_page_shows_the_pickup_time_contents_and_waiting_charge(): void
    {
        Queue::fake();
        $w = $this->world();
        $this->enableSchedule();
        $order = $this->create($w, ['delivery_type' => 'scheduled', 'scheduled_at' => now()->addHours(3), 'package_contents' => 'medicines']);
        $order->update(['waiting_minutes' => 30, 'waiting_charge' => 40]);

        Livewire::actingAs($w['customer'])->test(Show::class, ['parcelOrder' => $order->fresh()])
            ->assertSee('Scheduled pickup:')
            ->assertSee('Medicines')
            ->assertSee('Waiting at pickup (30 min), paid to the rider');
    }

    // ═══════════════════════ recipient SMS + vehicle ═══════════════════════

    public function test_the_recipient_sms_names_the_rider_and_their_phone(): void
    {
        $sms = new CapturingSmsAdapter;
        $this->app->instance(SmsAdapter::class, $sms);
        Setting::set('notifications.channels', 'sms');
        $w = $this->world();
        $w['rider']->user->update(['name' => 'Suresh']);
        $order = $this->create($w);
        DispatchAttempt::create(['dispatchable_type' => ParcelOrder::class, 'dispatchable_id' => $order->id, 'notifiable_type' => FieldWorker::class, 'notifiable_id' => $w['rider']->id, 'status' => 'notified', 'notified_at' => now()]);

        app(AcceptParcelOfferAction::class)->execute($order->id, $w['rider']);

        $message = $sms->lastMessageTo('9876543210');
        $this->assertStringContainsString('Rider: Suresh, '.$w['rider']->user->phone, $message);
    }

    public function test_a_rider_with_another_vehicle_is_not_offered_a_two_wheeler_parcel(): void
    {
        Queue::fake();
        $w = $this->world();
        $w['rider']->update(['vehicle_type' => 'three_wheeler']);
        $order = $this->create($w);

        $this->assertTrue(app(DispatchService::class)->findWorkerCandidates($order)->isEmpty());

        $w['rider']->update(['vehicle_type' => 'two_wheeler']);
        $this->assertCount(1, app(DispatchService::class)->findWorkerCandidates($order->fresh()));
    }

    // ═══════════════════════ franchise Parcel Dashboard ═══════════════════════

    public function test_the_dashboard_needs_parcel_orders_view(): void
    {
        Livewire::actingAs($this->makeUserWithNoPermissions())->test(Dashboard::class)->assertForbidden();
    }

    public function test_a_franchise_admin_counts_only_their_own_franchise_and_sees_no_earnings_without_the_grant(): void
    {
        Queue::fake();
        $mine = $this->world();
        $theirs = $this->world();
        $this->create($mine);
        $this->create($mine);
        $this->create($theirs);
        $actor = $this->makeUserWithPermission('parcel_orders.view', 'franchise', $mine['franchise']->id);

        Livewire::actingAs($actor)->test(Dashboard::class)
            ->assertSet('franchiseId', $mine['franchise']->id)
            ->assertViewHas('todayTotal', 2)
            ->assertViewHas('ridersTotal', 1)
            ->assertViewHas('earnings', null)
            ->set('franchiseId', $theirs['franchise']->id)
            ->assertSet('franchiseId', $mine['franchise']->id) // another franchise's id is never kept
            ->assertViewHas('todayTotal', 2);
    }

    public function test_super_admin_sees_every_franchise_and_the_parcel_earnings(): void
    {
        Queue::fake();
        $a = $this->world();
        $b = $this->world();
        $this->create($a);
        $this->create($b);

        Livewire::actingAs($this->makeSuperAdmin())->test(Dashboard::class)
            ->assertSet('franchiseId', null)
            ->assertViewHas('todayTotal', 2)
            ->assertViewHas('waitingCount', 0)
            ->assertViewHas('earnings', fn ($e) => is_array($e) && $e['month'] === 0.0)
            ->assertSee('Parcel Dashboard');
    }
}
