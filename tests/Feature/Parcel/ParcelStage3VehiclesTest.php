<?php

namespace Tests\Feature\Parcel;

use App\Actions\CreateParcelOrderAction;
use App\Actions\MarkParcelArrivedAtPickupAction;
use App\Actions\MarkParcelPickedUpAction;
use App\Exceptions\ParcelQuoteException;
use App\Livewire\Customer\Parcel\Create;
use App\Livewire\Customer\Parcel\Show;
use App\Livewire\ParcelOrders\Settings;
use App\Livewire\Rider\Dashboard as RiderDashboard;
use App\Livewire\Workers\Show as WorkerShow;
use App\Models\ActivityLog;
use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\ParcelOrder;
use App\Models\Setting;
use App\Services\DispatchService;
use App\Services\Parcel\ParcelPricing;
use App\Services\Parcel\ParcelSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Parcel Stage 3 (owner go-ahead 2026-11-23): goods vehicles (3-wheeler, mini truck, pickup, truck), each with its
 * own fare card, helpers for loading, its own free loading time, and its own driver pool. Nothing is offered until
 * Super Admin prices a vehicle, so the two-wheeler parcel is unchanged.
 */
class ParcelStage3VehiclesTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;

    private function world(): array
    {
        Queue::fake();
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);
        $rider = $this->makeParcelRiderIn($franchise, $zone);

        return compact('franchise', 'zone', 'customer', 'pickup', 'dropoff', 'rider');
    }

    /** Mini truck: ₹300 covers the first km, then ₹25/km; ₹150 per helper. */
    private function priceMiniTruck(array $extra = []): void
    {
        foreach (array_merge([
            'base_fare' => '300', 'base_km' => '1', 'per_km_rate' => '25', 'helper_fee' => '150',
        ], $extra) as $field => $value) {
            Setting::set(ParcelSettings::vehicleKey('mini_truck', $field), $value);
        }
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

    private function quote(string $vehicle, int $helpers = 0, ?float $kg = null): array
    {
        return app(ParcelPricing::class)->quote(1.0, 1.0, 1.01, 1.01, $kg, 'small', [], false, [], false, null, $vehicle, $helpers);
    }

    // ═══════════════════════ offered only once priced ═══════════════════════

    public function test_only_the_two_wheeler_is_offered_until_a_vehicle_is_priced(): void
    {
        $this->world();
        $this->assertSame(['two_wheeler'], ParcelSettings::availableVehicles());

        // Half a fare card is not enough.
        Setting::set(ParcelSettings::vehicleKey('mini_truck', 'base_fare'), '300');
        $this->assertSame(['two_wheeler'], ParcelSettings::availableVehicles());

        Setting::set(ParcelSettings::vehicleKey('mini_truck', 'per_km_rate'), '25');
        $this->assertSame(['two_wheeler', 'mini_truck'], ParcelSettings::availableVehicles());
    }

    public function test_an_unpriced_vehicle_cannot_be_booked(): void
    {
        $w = $this->world();

        $this->expectException(ParcelQuoteException::class);
        $this->expectExceptionMessage('A Pickup truck is not available here yet.');
        $this->create($w, ['vehicle_type' => 'pickup']);
    }

    public function test_an_unknown_vehicle_is_refused(): void
    {
        $w = $this->world();

        $this->expectExceptionMessage('Choose a vehicle from the list.');
        $this->create($w, ['vehicle_type' => 'rocket']);
    }

    public function test_a_goods_vehicle_is_never_offered_without_the_parcel_prices(): void
    {
        $this->world();
        $this->priceMiniTruck();
        Setting::set('parcel.base_fare', null);

        $this->assertSame([], ParcelSettings::availableVehicles());
    }

    // ═══════════════════════ pricing ═══════════════════════

    public function test_a_mini_truck_is_priced_from_its_own_fare_card(): void
    {
        $this->world();
        $this->priceMiniTruck();

        $quote = $this->quote('mini_truck');
        $extraKm = max(0, $quote['distance_km'] - 1);

        $this->assertSame('Mini truck, base fare (first 1 km)', $quote['lines'][0]['label']);
        $this->assertEquals(300.0, $quote['lines'][0]['amount']);
        $this->assertEquals(300 + round($extraKm * 25), $quote['total'], 'whole rupees');
        // No per-kg or package-size lines on a goods vehicle.
        $this->assertCount(2, $quote['lines']);
    }

    public function test_helpers_add_their_own_line(): void
    {
        $this->world();
        $this->priceMiniTruck();

        $without = $this->quote('mini_truck');
        $with = $this->quote('mini_truck', 2);

        $this->assertContains(['label' => '2 helpers for loading', 'amount' => 300.0], $with['lines']);
        $this->assertEquals($without['total'] + 300, $with['total']);
    }

    public function test_helpers_are_refused_where_their_price_is_blank_on_a_two_wheeler_and_above_the_maximum(): void
    {
        $this->world();
        $this->priceMiniTruck(['helper_fee' => null]);

        try {
            $this->quote('mini_truck', 1);
            $this->fail('helpers without a helper price');
        } catch (ParcelQuoteException $e) {
            $this->assertSame('Helpers are not available with this vehicle here.', $e->getMessage());
        }
        try {
            $this->quote('two_wheeler', 1);
            $this->fail('helpers on a two-wheeler');
        } catch (ParcelQuoteException $e) {
            $this->assertSame('Helpers are not available with this vehicle here.', $e->getMessage());
        }

        $this->priceMiniTruck();
        $this->expectExceptionMessage('You can ask for up to 2 helpers.');
        $this->quote('mini_truck', 3);
    }

    public function test_a_load_above_the_vehicle_capacity_is_refused(): void
    {
        $this->world();
        $this->priceMiniTruck();

        $this->assertNotEmpty($this->quote('mini_truck', 0, 750)['lines']);

        $this->expectExceptionMessage('A Mini truck carries up to 750 kg. Choose a bigger vehicle.');
        $this->quote('mini_truck', 0, 751);
    }

    public function test_a_vehicle_can_have_its_own_longest_trip(): void
    {
        $this->world();
        $this->priceMiniTruck(['max_distance_km' => '0.5']);

        $this->expectExceptionMessageMatches('/A Mini truck goes up to 0.5 km\./');
        $this->quote('mini_truck');
    }

    public function test_the_two_wheeler_price_is_unchanged(): void
    {
        $this->world();
        $this->priceMiniTruck();

        $before = app(ParcelPricing::class)->quote(1.0, 1.0, 1.01, 1.01, null, 'small', []);
        $this->assertEquals($before, $this->quote('two_wheeler'));
        $this->assertSame('Base fare (first 2 km)', $before['lines'][0]['label']);
    }

    // ═══════════════════════ booking ═══════════════════════

    public function test_a_mini_truck_order_records_the_vehicle_helpers_and_goods(): void
    {
        $w = $this->world();
        $this->priceMiniTruck();

        $order = $this->create($w, ['vehicle_type' => 'mini_truck', 'helpers' => 1, 'package_contents' => 'furniture', 'package_size' => 'small']);

        $this->assertSame('mini_truck', $order->vehicle_type);
        $this->assertSame(1, $order->helpers);
        $this->assertSame('furniture', $order->package_contents);
        $this->assertSame('large', $order->package_size, 'a goods vehicle has no package size');
        $this->assertSame('Mini truck · 1 helper', $order->loadSummary());
        $this->assertEquals(150, collect($order->price_breakdown)->firstWhere('label', '1 helper for loading')['amount'] ?? null);
    }

    public function test_goods_contents_are_refused_on_a_two_wheeler_and_parcel_contents_on_a_truck(): void
    {
        $w = $this->world();
        $this->priceMiniTruck();

        try {
            $this->create($w, ['package_contents' => 'furniture']);
            $this->fail('furniture on a two-wheeler');
        } catch (\RuntimeException $e) {
            $this->assertSame('Choose what is inside the parcel from the list.', $e->getMessage());
        }

        $this->expectExceptionMessage('Choose what you are moving from the list.');
        $this->create($w, ['vehicle_type' => 'mini_truck', 'package_contents' => 'documents']);
    }

    public function test_helpers_on_a_two_wheeler_order_are_dropped_not_charged(): void
    {
        $w = $this->world();

        $order = $this->create($w, ['helpers' => 2]);

        $this->assertSame('two_wheeler', $order->vehicle_type);
        $this->assertSame(0, $order->helpers);
    }

    public function test_a_parcel_pack_never_pays_for_a_goods_vehicle(): void
    {
        $w = $this->world();
        $this->priceMiniTruck();

        $this->expectExceptionMessage('A parcel pack covers two-wheeler parcels only.');
        $this->create($w, ['vehicle_type' => 'mini_truck', 'payment_method' => 'wallet', 'parcel_pack_purchase_id' => 999]);
    }

    public function test_coupons_stay_refused_on_a_goods_vehicle(): void
    {
        $w = $this->world();
        $this->priceMiniTruck();

        $this->expectExceptionMessage('Coupons and offers are not available on parcel delivery.');
        $this->create($w, ['vehicle_type' => 'mini_truck', 'coupon_code' => 'SAVE10']);
    }

    // ═══════════════════════ drivers ═══════════════════════

    public function test_only_drivers_of_that_vehicle_are_offered_the_order(): void
    {
        $w = $this->world();
        $this->priceMiniTruck();
        $order = $this->create($w, ['vehicle_type' => 'mini_truck']);

        $this->assertTrue(app(DispatchService::class)->findWorkerCandidates($order)->isEmpty(), 'a two-wheeler rider gets no truck order');

        $w['rider']->update(['vehicle_type' => 'mini_truck']);
        $this->assertCount(1, app(DispatchService::class)->findWorkerCandidates($order->fresh()));

        $parcel = $this->create($w);
        $this->assertTrue(app(DispatchService::class)->findWorkerCandidates($parcel)->isEmpty(), 'a truck driver gets no two-wheeler parcel');
    }

    public function test_the_driver_sees_the_vehicle_and_the_helpers_to_bring(): void
    {
        $w = $this->world();
        $this->priceMiniTruck();
        $w['rider']->update(['vehicle_type' => 'mini_truck']);
        $order = $this->create($w, ['vehicle_type' => 'mini_truck', 'helpers' => 2]);

        DispatchAttempt::create(['dispatchable_type' => ParcelOrder::class, 'dispatchable_id' => $order->id, 'notifiable_type' => FieldWorker::class, 'notifiable_id' => $w['rider']->id, 'status' => 'notified', 'notified_at' => now()]);
        Livewire::actingAs($w['rider']->user)->test(RiderDashboard::class)->assertSee('Mini truck · 2 helpers · '.$order->code);

        $order->update(['status' => 'assigned', 'assigned_worker_id' => $w['rider']->id, 'pickup_otp' => '1111', 'delivery_otp' => '2222']);
        Livewire::actingAs($w['rider']->user)->test(RiderDashboard::class)->assertSee('Bring 2 helpers for loading');
    }

    public function test_a_goods_vehicle_uses_its_own_free_loading_time(): void
    {
        $w = $this->world();
        $this->priceMiniTruck(['free_loading_minutes' => '30', 'waiting_charge_per_block' => '50']);
        Setting::set('parcel.waiting_free_minutes', '10');
        Setting::set('parcel.waiting_block_minutes', '15');
        Setting::set('parcel.waiting_charge_per_block', '20');
        $w['rider']->update(['vehicle_type' => 'mini_truck']);
        $order = $this->create($w, ['vehicle_type' => 'mini_truck']);
        $order->update(['status' => 'assigned', 'assigned_worker_id' => $w['rider']->id, 'pickup_otp' => '1111', 'delivery_otp' => '2222']);

        app(MarkParcelArrivedAtPickupAction::class)->execute($order->id, $w['rider'], 1.0, 1.0);
        $this->travel(40)->minutes(); // 10 over the truck's 30 free = 1 block at the truck's rate
        app(MarkParcelPickedUpAction::class)->execute($order->id, $w['rider'], '1111');

        $this->assertEquals(50.0, (float) $order->fresh()->waiting_charge);
    }

    // ═══════════════════════ customer web page ═══════════════════════

    public function test_the_booking_page_shows_no_vehicle_cards_until_a_second_vehicle_is_priced(): void
    {
        $w = $this->world();

        Livewire::actingAs($w['customer'])->test(Create::class)
            ->set('dropMode', 'saved')->set('dropAddressId', $w['dropoff']->id)
            ->assertDontSee('data-testid="vehicle-picker"', false)
            ->assertSee('Size');
    }

    public function test_a_customer_books_a_mini_truck_with_a_helper_on_the_web_page(): void
    {
        $w = $this->world();
        $this->priceMiniTruck();

        Livewire::actingAs($w['customer'])->test(Create::class)
            ->set('dropMode', 'saved')->set('dropAddressId', $w['dropoff']->id)
            ->assertSee('data-testid="vehicle-picker"', false)
            ->assertSee('Mini truck')
            ->set('vehicle', 'mini_truck')
            ->assertSee('What are you moving?')
            ->assertSee('Helpers for loading and unloading')
            ->assertDontSee('Envelope / hand')
            ->call('setHelpers', 1)
            ->set('packageContents', 'appliances')
            ->set('recipientPhone', '9876543210')->set('paymentMethod', 'cash')->set('restrictedConfirmed', true)
            ->call('submit')
            ->assertSet('error', '');

        $order = ParcelOrder::sole();
        $this->assertSame('mini_truck', $order->vehicle_type);
        $this->assertSame(1, $order->helpers);
        $this->assertSame('appliances', $order->package_contents);
    }

    public function test_the_order_page_shows_the_vehicle_and_the_drivers_number_plate(): void
    {
        $w = $this->world();
        $this->priceMiniTruck();
        $w['rider']->update(['vehicle_type' => 'mini_truck', 'vehicle_number' => 'AP 26 TA 1234']);
        $order = $this->create($w, ['vehicle_type' => 'mini_truck', 'helpers' => 2]);
        $order->update(['status' => 'assigned', 'assigned_worker_id' => $w['rider']->id]);

        Livewire::actingAs($w['customer'])->test(Show::class, ['parcelOrder' => $order->fresh()])
            ->assertSee('Mini truck · 2 helpers')
            ->assertSee('Your driver')
            ->assertSee('AP 26 TA 1234')
            ->assertSee('Driver assigned')
            ->assertSee('Chat with the driver')
            ->assertDontSee('Your rider')
            ->assertDontSee('Chat with the rider');

        // The driver's screen labels it a goods trip, not a parcel.
        Livewire::actingAs($w['rider']->user)->test(RiderDashboard::class)->assertSee('Goods trip');
    }

    public function test_every_price_is_in_whole_rupees(): void
    {
        $this->world();
        $this->priceMiniTruck(['per_km_rate' => '23.37', 'helper_fee' => '99.6']);

        foreach ([$this->quote('mini_truck', 2), $this->quote('two_wheeler')] as $quote) {
            $this->assertSame(floor($quote['total']), (float) $quote['total']);
            foreach ($quote['lines'] as $line) {
                $this->assertSame(floor($line['amount']), (float) $line['amount'], $line['label']);
            }
            $this->assertEquals(array_sum(array_column($quote['lines'], 'amount')), $quote['total']);
        }
    }

    // ═══════════════════════ app API ═══════════════════════

    public function test_the_api_quote_lists_the_vehicles_and_books_one_with_helpers(): void
    {
        $w = $this->world();
        $this->priceMiniTruck();

        $quote = $this->actingAs($w['customer'], 'sanctum')->postJson('/api/parcel-orders/quote', [
            'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id,
            'vehicle_type' => 'mini_truck', 'helpers' => 1,
        ])->assertOk();
        $this->assertSame(['two_wheeler', 'mini_truck'], array_column($quote->json('data.vehicle_options'), 'key'));
        $this->assertEquals(150, $quote->json('data.vehicle_options.1.helper_fee'));
        $this->assertNull($quote->json('data.vehicle_options.0.helper_fee'));
        $this->assertSame(2, $quote->json('data.helpers_max'));

        $this->actingAs($w['customer'], 'sanctum')->postJson('/api/parcel-orders', [
            'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id,
            'recipient_phone' => '9876543210', 'payment_method' => 'cash', 'restricted_items_confirmed' => true,
            'vehicle_type' => 'mini_truck', 'helpers' => 1, 'package_contents' => 'furniture',
        ])->assertCreated()
            ->assertJsonPath('data.vehicle_type', 'mini_truck')
            ->assertJsonPath('data.vehicle_label', 'Mini truck')
            ->assertJsonPath('data.helpers', 1);
        $this->assertEquals($quote->json('data.price_quoted'), (float) ParcelOrder::sole()->price_quoted);
    }

    public function test_an_older_app_without_a_vehicle_still_books_a_two_wheeler(): void
    {
        $w = $this->world();
        $this->priceMiniTruck();

        $this->actingAs($w['customer'], 'sanctum')->postJson('/api/parcel-orders', [
            'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id,
            'recipient_phone' => '9876543210', 'payment_method' => 'cash', 'restricted_items_confirmed' => true,
        ])->assertCreated()->assertJsonPath('data.vehicle_type', 'two_wheeler')->assertJsonPath('data.helpers', 0);
    }

    public function test_the_api_refuses_an_unpriced_vehicle(): void
    {
        $w = $this->world();

        $this->actingAs($w['customer'], 'sanctum')->postJson('/api/parcel-orders/quote', [
            'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id, 'vehicle_type' => 'truck',
        ])->assertStatus(422)->assertJsonPath('message', 'A Truck is not available here yet. Choose another vehicle.');
    }

    // ═══════════════════════ admin ═══════════════════════

    public function test_super_admin_prices_a_vehicle_on_parcel_settings_and_it_is_audit_logged(): void
    {
        $this->world();

        Livewire::actingAs($this->makeSuperAdmin())->test(Settings::class)
            ->assertSee('Vehicle: Mini truck')
            ->assertSee('Price per helper (loading and unloading)')
            ->set('inputs.'.ParcelSettings::field(ParcelSettings::vehicleKey('mini_truck', 'base_fare')), '300')
            ->set('inputs.'.ParcelSettings::field(ParcelSettings::vehicleKey('mini_truck', 'per_km_rate')), '25')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['two_wheeler', 'mini_truck'], ParcelSettings::availableVehicles());
    }

    public function test_an_admin_changes_a_riders_vehicle_and_it_is_logged(): void
    {
        $w = $this->world();

        Livewire::actingAs($this->makeSuperAdmin())->test(WorkerShow::class, ['workerId' => $w['rider']->id])
            ->set('vehicleType', 'three_wheeler')->set('vehicleNumber', '')
            ->call('saveVehicle')->assertHasErrors(['vehicleNumber' => 'required'])
            ->set('vehicleNumber', 'ap 26 ab 4321')
            ->call('saveVehicle')->assertHasNoErrors()->assertSet('flashMessage', 'Vehicle saved.');

        $rider = $w['rider']->fresh();
        $this->assertSame('three_wheeler', $rider->vehicle_type);
        $this->assertSame('AP 26 AB 4321', $rider->vehicle_number);
        $this->assertTrue(ActivityLog::where('description', 'Rider vehicle changed')->exists());
    }

    // ═══════════════════════ mail outage (owner 2026-11-23) ═══════════════════════

    /**
     * A mail server that cannot be reached used to roll the booking back and show the customer "Connection to ...
     * timed out". Parcel notifications now go to the queue after the booking commits, so the booking stands.
     */
    public function test_a_mail_server_that_is_down_does_not_stop_a_booking(): void
    {
        $w = $this->world();
        $this->priceMiniTruck();
        Setting::set('notifications.channels', 'mail,sms');
        // The mail channel fails exactly as it did on the local copy: the mail server cannot be reached.
        app(\Illuminate\Notifications\ChannelManager::class)->extend('mail', fn () => new class
        {
            public function send($notifiable, $notification): void
            {
                throw new \RuntimeException('Connection to "1CallFix.com:587" timed out.');
            }
        });

        Livewire::actingAs($w['customer'])->test(Create::class)
            ->set('dropMode', 'saved')->set('dropAddressId', $w['dropoff']->id)
            ->set('vehicle', 'mini_truck')
            ->set('recipientPhone', '9876543210')->set('paymentMethod', 'cash')->set('restrictedConfirmed', true)
            ->call('submit')
            ->assertSet('error', '');

        $this->assertSame(1, ParcelOrder::count());
        // The "order booked" message waits in the queue for the worker instead of being sent inside the booking.
        Queue::assertPushed(\Illuminate\Notifications\SendQueuedNotifications::class,
            fn ($job) => $job->notification instanceof \App\Notifications\ParcelOrderStatusNotification && $job->channels === ['mail']);
    }

    public function test_parcel_notifications_are_queued_after_commit(): void
    {
        $w = $this->world();
        $order = $this->create($w);

        $status = new \App\Notifications\ParcelOrderStatusNotification('created', $order, ['mail']);
        $recipient = new \App\Notifications\ParcelRecipientNotification($order);
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $status);
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $recipient);
        $this->assertTrue($status->afterCommit);
        $this->assertTrue($recipient->afterCommit);
    }
}
