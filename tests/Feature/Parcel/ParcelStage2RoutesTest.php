<?php

namespace Tests\Feature\Parcel;

use App\Actions\AcceptParcelOfferAction;
use App\Actions\CreateParcelOrderAction;
use App\Actions\MarkParcelDeliveredAction;
use App\Actions\MarkParcelReturnedAction;
use App\Contracts\SmsAdapter;
use App\Exceptions\ParcelQuoteException;
use App\Livewire\Customer\Parcel\Create;
use App\Livewire\Rider\Dashboard as RiderDashboard;
use App\Models\Address;
use App\Models\Commission;
use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\ParcelOrder;
use App\Models\Setting;
use App\Services\DispatchService;
use App\Services\Parcel\ParcelPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\Support\CapturingSmsAdapter;
use Tests\TestCase;

/**
 * Parcel Stage 2 — routes (owner decisions 2026-10-31): multi-drop (up to N drop points, route distance + a fee per
 * extra drop, one payment, a code per drop, cash only at pickup) and the return trip (X% of the delivery price, the
 * sender's return code finishes the order).
 */
class ParcelStage2RoutesTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function world(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);
        $second = Address::create([
            'user_id' => $customer->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'label' => 'Second', 'lat' => 1.02, 'lng' => 1.02, 'address_line' => 'Second Addr',
        ]);
        $rider = $this->makeParcelRiderIn($franchise, $zone);

        return compact('franchise', 'zone', 'customer', 'pickup', 'dropoff', 'second', 'rider');
    }

    private function enableMultiDrop(string $max = '3', string $fee = '15'): void
    {
        Setting::set('parcel.multi_drop_max_stops', $max);
        Setting::set('parcel.extra_stop_fee', $fee);
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

    private function withSecondDrop(array $w): array
    {
        return ['extra_stops' => [['address_id' => $w['second']->id, 'recipient_name' => 'Sita', 'recipient_phone' => '9123456780']]];
    }

    private function accept(array $w, ParcelOrder $order): ParcelOrder
    {
        DispatchAttempt::create(['dispatchable_type' => ParcelOrder::class, 'dispatchable_id' => $order->id, 'notifiable_type' => FieldWorker::class, 'notifiable_id' => $w['rider']->id, 'status' => 'notified', 'notified_at' => now()]);

        return app(AcceptParcelOfferAction::class)->execute($order->id, $w['rider'])->load('stops');
    }

    private function pickedUp(ParcelOrder $order): ParcelOrder
    {
        $order->update(['status' => 'picked_up', 'picked_up_at' => now(), 'payment_status' => 'paid']);

        return $order->fresh('stops');
    }

    // ═══════════════════════ pricing ═══════════════════════

    public function test_multi_drop_is_refused_while_its_settings_are_unset(): void
    {
        $w = $this->world();

        $this->expectException(ParcelQuoteException::class);
        $this->expectExceptionMessage('Only one drop point is available here.');
        $this->create($w, $this->withSecondDrop($w));
    }

    public function test_multi_drop_needs_both_the_max_and_the_fee(): void
    {
        $w = $this->world();
        Setting::set('parcel.multi_drop_max_stops', '3'); // no fee set

        $this->expectException(ParcelQuoteException::class);
        $this->create($w, $this->withSecondDrop($w));
    }

    public function test_the_route_distance_runs_through_every_drop_and_each_extra_drop_adds_the_fee(): void
    {
        $w = $this->world();
        $this->enableMultiDrop(fee: '15');
        $haversine = app(DispatchService::class);
        $route = round($haversine->haversineKm(1.0, 1.0, 1.01, 1.01) + $haversine->haversineKm(1.01, 1.01, 1.02, 1.02), 2);

        $quote = app(ParcelPricing::class)->quote(1.0, 1.0, 1.01, 1.01, null, 'small', ['franchise_id' => $w['franchise']->id], false, [[1.02, 1.02]]);

        $this->assertEqualsWithDelta($route, $quote['distance_km'], 0.01);
        $this->assertContains(['label' => '1 extra drop point', 'amount' => 15.0], $quote['lines']);
        $this->assertEqualsWithDelta(40 + max(0, $quote['distance_km'] - 2) * 10 + 15, $quote['total'], 0.02);
    }

    public function test_no_more_drops_than_the_setting_allows(): void
    {
        $w = $this->world();
        $this->enableMultiDrop(max: '2');

        $this->expectExceptionMessage('You can add up to 2 drop points.');
        app(ParcelPricing::class)->quote(1.0, 1.0, 1.01, 1.01, null, 'small', ['franchise_id' => $w['franchise']->id], false, [[1.02, 1.02], [1.015, 1.015]]);
    }

    public function test_every_drop_must_be_inside_the_longest_delivery_distance(): void
    {
        $w = $this->world();
        $this->enableMultiDrop();
        Setting::set('parcel.max_distance_km', '3');

        $this->expectExceptionMessage('Drop 2 is');
        app(ParcelPricing::class)->quote(1.0, 1.0, 1.01, 1.01, null, 'small', ['franchise_id' => $w['franchise']->id], false, [[1.1, 1.1]]);
    }

    public function test_the_return_trip_adds_its_percentage_of_the_delivery_price_and_is_refused_when_unset(): void
    {
        $w = $this->world();
        $scope = ['franchise_id' => $w['franchise']->id];

        try {
            app(ParcelPricing::class)->quote(1.0, 1.0, 1.01, 1.01, null, 'small', $scope, false, [], true);
            $this->fail('A return trip must be refused while its percentage is unset.');
        } catch (ParcelQuoteException $e) {
            $this->assertSame('Return to sender is not available here.', $e->getMessage());
        }

        Setting::set('parcel.return_trip_percent', '50');
        $oneWay = app(ParcelPricing::class)->quote(1.0, 1.0, 1.01, 1.01, null, 'small', $scope);
        $both = app(ParcelPricing::class)->quote(1.0, 1.0, 1.01, 1.01, null, 'small', $scope, false, [], true);

        $this->assertSame('Return to sender (50%)', end($both['lines'])['label']);
        $this->assertEqualsWithDelta($oneWay['total'] * 1.5, $both['total'], 0.01);
    }

    // ═══════════════════════ booking ═══════════════════════

    public function test_a_multi_drop_order_saves_its_stops_and_charges_the_route(): void
    {
        $w = $this->world();
        $this->enableMultiDrop();

        $order = $this->create($w, $this->withSecondDrop($w));

        $this->assertCount(1, $order->stops);
        $this->assertSame(2, $order->stops->first()->sequence);
        $this->assertSame('9123456780', $order->stops->first()->recipient_phone);
        $this->assertContains('1 extra drop point', array_column($order->price_breakdown, 'label'));
    }

    public function test_a_stop_must_be_the_customers_own_address(): void
    {
        $w = $this->world();
        $this->enableMultiDrop();
        $stranger = Address::create(['user_id' => $this->makeCustomer()->id, 'lat' => 1.02, 'lng' => 1.02, 'address_line' => 'Not yours', 'label' => 'x']);

        $this->expectExceptionMessage('Drop 2: choose where we deliver it.');
        $this->create($w, ['extra_stops' => [['address_id' => $stranger->id, 'recipient_phone' => '9123456780']]]);
    }

    public function test_cash_on_a_multi_drop_order_is_paid_at_pickup_only(): void
    {
        $w = $this->world();
        $this->enableMultiDrop();

        $this->expectExceptionMessage('With more than one drop point, cash is paid at pickup.');
        $this->create($w, $this->withSecondDrop($w) + ['cash_collect_at' => 'drop']);
    }

    // ═══════════════════════ codes ═══════════════════════

    public function test_accepting_issues_a_code_per_drop_and_a_return_code_and_texts_each_recipient(): void
    {
        $sms = new CapturingSmsAdapter;
        $this->app->instance(SmsAdapter::class, $sms);
        Setting::set('notifications.channels', 'sms');
        $w = $this->world();
        $this->enableMultiDrop();
        Setting::set('parcel.return_trip_percent', '50');

        $order = $this->accept($w, $this->create($w, $this->withSecondDrop($w) + ['return_trip' => true]));

        $this->assertNotNull($order->return_otp);
        $stop = $order->stops->first();
        $this->assertNotNull($stop->delivery_otp);
        $this->assertStringContainsString('OTP '.$order->delivery_otp, $sms->lastMessageTo('9876543210'));
        $this->assertStringContainsString('OTP '.$stop->delivery_otp, $sms->lastMessageTo('9123456780'));
    }

    // ═══════════════════════ delivery ═══════════════════════

    public function test_each_drop_needs_its_own_code_and_the_order_finishes_after_the_last(): void
    {
        $w = $this->world();
        $this->enableMultiDrop();
        $order = $this->pickedUp($this->accept($w, $this->create($w, $this->withSecondDrop($w))));
        $stop = $order->stops->first();
        $deliver = app(MarkParcelDeliveredAction::class);

        $after1 = $deliver->execute($order->id, $w['rider'], $order->delivery_otp);
        $this->assertSame('en_route_dropoff', $after1->status);
        $this->assertNotNull($after1->drop_delivered_at);
        $this->assertSame(0, Commission::where('parcel_order_id', $order->id)->count());

        try {
            $deliver->execute($order->id, $w['rider'], $order->delivery_otp, $stop->id); // drop 1's code at drop 2
            $this->fail('A stop must refuse another drop\'s code.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Incorrect delivery OTP.', $e->getMessage());
        }

        $done = $deliver->execute($order->id, $w['rider'], $stop->delivery_otp, $stop->id);
        $this->assertSame('delivered', $done->status);
        $this->assertNotNull($stop->fresh()->delivered_at);
        $this->assertSame(1, Commission::where('parcel_order_id', $order->id)->count());
    }

    public function test_drops_can_be_done_in_any_order_but_never_twice(): void
    {
        $w = $this->world();
        $this->enableMultiDrop();
        $order = $this->pickedUp($this->accept($w, $this->create($w, $this->withSecondDrop($w))));
        $stop = $order->stops->first();
        $deliver = app(MarkParcelDeliveredAction::class);

        $deliver->execute($order->id, $w['rider'], $stop->delivery_otp, $stop->id);

        $this->expectExceptionMessage('This drop is already done.');
        $deliver->execute($order->id, $w['rider'], $stop->delivery_otp, $stop->id);
    }

    public function test_a_stop_from_another_order_is_refused(): void
    {
        $w = $this->world();
        $this->enableMultiDrop();
        $other = $this->create($w, $this->withSecondDrop($w));
        $order = $this->pickedUp($this->accept($w, $this->create($w, $this->withSecondDrop($w))));

        $this->expectExceptionMessage('That drop point is not on this parcel.');
        app(MarkParcelDeliveredAction::class)->execute($order->id, $w['rider'], '0000', $other->stops()->first()->id);
    }

    public function test_a_return_trip_comes_back_to_the_sender_and_finishes_with_the_return_code(): void
    {
        $w = $this->world();
        Setting::set('parcel.return_trip_percent', '50');
        $order = $this->pickedUp($this->accept($w, $this->create($w, ['return_trip' => true])));

        $returning = app(MarkParcelDeliveredAction::class)->execute($order->id, $w['rider'], $order->delivery_otp);
        $this->assertTrue($returning->isReturning());
        $this->assertSame('en_route_dropoff', $returning->status);
        $this->assertSame(0, Commission::where('parcel_order_id', $order->id)->count());

        try {
            app(MarkParcelDeliveredAction::class)->execute($order->id, $w['rider'], $order->delivery_otp);
            $this->fail('Every drop is done; delivering again must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Take the return back', $e->getMessage());
        }

        try {
            app(MarkParcelReturnedAction::class)->execute($order->id, $w['rider'], 'nope');
            $this->fail('A wrong return code must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Incorrect return code.', $e->getMessage());
        }

        $done = app(MarkParcelReturnedAction::class)->execute($order->id, $w['rider'], $order->return_otp);
        $this->assertSame('delivered', $done->status);
        $this->assertNotNull($done->returned_at);
        $this->assertSame(1, Commission::where('parcel_order_id', $order->id)->count());
    }

    public function test_the_return_cannot_be_confirmed_before_every_drop_is_done(): void
    {
        $w = $this->world();
        Setting::set('parcel.return_trip_percent', '50');
        $order = $this->pickedUp($this->accept($w, $this->create($w, ['return_trip' => true])));

        $this->expectExceptionMessage('This parcel is not on its way back to the sender.');
        app(MarkParcelReturnedAction::class)->execute($order->id, $w['rider'], $order->return_otp);
    }

    public function test_a_one_drop_order_still_finishes_on_its_delivery_code(): void
    {
        $w = $this->world();
        $order = $this->pickedUp($this->accept($w, $this->create($w)));

        $done = app(MarkParcelDeliveredAction::class)->execute($order->id, $w['rider'], $order->delivery_otp);

        $this->assertSame('delivered', $done->status);
        $this->assertNull($done->return_otp);
    }

    // ═══════════════════════ rider web ═══════════════════════

    public function test_the_rider_page_delivers_each_drop_and_the_return(): void
    {
        $w = $this->world();
        $this->enableMultiDrop();
        Setting::set('parcel.return_trip_percent', '50');
        $order = $this->pickedUp($this->accept($w, $this->create($w, $this->withSecondDrop($w) + ['return_trip' => true])));
        $stop = $order->stops->first();

        Livewire::actingAs($w['rider']->user)->test(RiderDashboard::class)
            ->assertSee('Drop 2: code from Sita')
            ->set('deliveryOtp', $order->delivery_otp)->call('confirmDelivery', $order->id)
            ->assertSet('notice', 'Drop done. Head to the next one.')
            ->set('stopOtps.'.$stop->id, $stop->delivery_otp)->call('confirmStop', $order->id, $stop->id)
            ->assertSet('notice', 'Every drop done. Take the return back to the sender.')
            ->assertSee('Return code from the sender')
            ->set('returnOtp', $order->return_otp)->call('confirmReturn', $order->id)
            ->assertSet('notice', 'Returned to the sender. Well done!');

        $this->assertSame('delivered', $order->fresh()->status);
    }

    // ═══════════════════════ customer web ═══════════════════════

    public function test_the_customer_page_books_a_second_drop_and_a_return(): void
    {
        $w = $this->world();
        $this->enableMultiDrop();
        Setting::set('parcel.return_trip_percent', '50');

        $page = Livewire::actingAs($w['customer'])->test(Create::class)
            ->set('pickupMode', 'saved')->set('pickupAddressId', $w['pickup']->id)
            ->set('dropMode', 'saved')->set('dropAddressId', $w['dropoff']->id)
            ->set('recipientPhone', '9876543210')
            ->assertSee('Bring something back to me')
            ->call('addDrop');
        $key = $page->get('extraDrops')[0]['key'];
        $page->call('setExtraDropPin', $key, 1.02, 1.02, 'Second place')
            ->set('extraDrops.0.phone', '9123456780')
            ->set('returnTrip', true)
            ->assertSee('1 extra drop point')
            ->set('paymentMethod', 'cash')->set('restrictedConfirmed', true)
            ->call('submit')
            ->assertHasNoErrors();

        $order = ParcelOrder::with('stops.address')->sole();
        $this->assertTrue($order->return_trip);
        $this->assertSame('9123456780', $order->stops->first()->recipient_phone);
        $this->assertTrue((bool) $order->stops->first()->address->is_parcel_only);
    }

    public function test_the_customer_page_offers_neither_option_while_unset(): void
    {
        $w = $this->world();

        Livewire::actingAs($w['customer'])->test(Create::class)
            ->set('pickupMode', 'saved')->set('pickupAddressId', $w['pickup']->id)
            ->assertDontSee('Add another drop point')
            ->assertDontSee('Bring something back to me')
            ->call('addDrop')
            ->assertSet('extraDrops', []);
    }

    // ═══════════════════════ API ═══════════════════════

    public function test_the_api_quotes_books_and_delivers_a_multi_drop_return_trip(): void
    {
        $w = $this->world();
        $this->enableMultiDrop();
        Setting::set('parcel.return_trip_percent', '50');
        Setting::set('payment.cash_enabled', '1');
        $payload = [
            'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id,
            'recipient_phone' => '9876543210', 'restricted_items_confirmed' => true, 'payment_method' => 'cash',
            'extra_stops' => [['address_id' => $w['second']->id, 'recipient_phone' => '9123456780']],
            'return_trip' => true,
        ];

        $this->actingAs($w['customer'], 'sanctum')->postJson('/api/parcel-orders/quote', $payload)
            ->assertOk()
            ->assertJsonPath('data.max_drop_points', 3)
            ->assertJsonPath('data.return_trip_available', true);

        $id = $this->actingAs($w['customer'], 'sanctum')->postJson('/api/parcel-orders', $payload)
            ->assertCreated()
            ->assertJsonPath('data.return_trip', true)
            ->assertJsonPath('data.stops.0.recipient_phone', '9123456780')
            ->json('data.id');

        $order = $this->pickedUp($this->accept($w, ParcelOrder::findOrFail($id)));
        $stop = $order->stops->first();
        $this->actingAs($w['rider']->user, 'sanctum')->postJson("/api/worker/parcel-orders/{$id}/deliver", ['otp' => $order->delivery_otp])->assertOk()->assertJsonPath('message', 'Drop done.');
        $this->actingAs($w['rider']->user, 'sanctum')->postJson("/api/worker/parcel-orders/{$id}/deliver", ['otp' => $stop->delivery_otp, 'stop_id' => $stop->id])->assertOk()->assertJsonPath('parcel_order.returning', true);
        $this->actingAs($w['rider']->user, 'sanctum')->postJson("/api/worker/parcel-orders/{$id}/return", ['otp' => $order->return_otp])->assertOk()->assertJsonPath('parcel_order.status', 'delivered');
    }
}
