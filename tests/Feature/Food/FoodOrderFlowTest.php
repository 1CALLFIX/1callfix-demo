<?php

namespace Tests\Feature\Food;

use App\Actions\AcceptMarketplaceDeliveryOfferAction;
use App\Actions\AdminCancelMarketplaceOrderAction;
use App\Actions\AdvanceMarketplaceOrderAction;
use App\Actions\CompleteMarketplaceOrderAction;
use App\Actions\CreateMarketplaceOrderAction;
use App\Contracts\PaymentGateway;
use App\Jobs\MarketplaceDispatchJob;
use App\Livewire\Seller\Orders as SellerOrders;
use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\MarketplaceOrder;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreHour;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CartService;
use App\Services\Marketplace\MarketplacePaymentService;
use App\Services\Marketplace\MarketplaceSettlementService;
use App\Support\Modules;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * Food F2 (2026-11-03) — the restaurant order flow on the shared marketplace engine. Proven here: checkout refuses a
 * closed / unlicensed restaurant, a sold-out dish and a choice that breaks its group's rule; dishes are never counted
 * stock; packaging, distance fee, radius and 9(5) GST all come from Food Settings (unset = off); the restaurant must
 * accept within food.accept_minutes (clock starts on payment for online orders) or the order is cancelled and
 * refunded in full; accepting promises a ready time; a rider is called before that time only when Super Admin sets
 * it, and can deliver only once the food is ready; a customer can cancel only before the restaurant accepts.
 */
class FoodOrderFlowTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use RefreshDatabase;

    private $franchise;
    private $zone;
    private User $customer;
    private Store $restaurant;
    private Product $dish;

    protected function setUp(): void
    {
        parent::setUp();
        [, , $this->franchise, $this->zone] = $this->makeFranchiseTree();
        $this->activateMarketplaceModuleFor($this->franchise, Modules::FOOD);
        $this->customer = $this->makeCustomer();
        $this->restaurant = $this->makeStore($this->franchise, $this->zone, null, [
            'module' => Modules::FOOD, 'name' => 'Annapurna Mess', 'fssai_number' => '12345678901234',
            'delivery_fee_flat' => 30, 'prep_minutes' => 20, 'tax_percent' => 18,
        ]);
        $this->dish = $this->makeProduct($this->restaurant, ['name' => 'Veg Meals', 'price' => 100, 'stock' => 9999, 'food_type' => 'veg']);
    }

    private function order(string $payment = 'cash', string $type = 'pickup', array $extra = [], ?Product $dish = null, array $addOnIds = [], int $qty = 2): MarketplaceOrder
    {
        app(CartService::class)->add($this->customer, $dish ?? $this->dish, $qty, null, $addOnIds);

        return app(CreateMarketplaceOrderAction::class)->execute(array_merge([
            'store_id' => $this->restaurant->id, 'customer_id' => $this->customer->id,
            'order_type' => $type, 'payment_method' => $payment,
        ], $extra));
    }

    private function addressAtKm(float $km)
    {
        $address = $this->makeAddress($this->customer, $this->franchise, $this->zone);
        $address->forceFill(['lat' => 1.0 + $km / 111.19, 'lng' => 1.0])->save();

        return $address;
    }

    private function assertRefused(string $message, callable $step): void
    {
        try {
            $step();
            $this->fail("Expected refusal: {$message}");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    // ── checkout checks ─────────────────────────────────────────────────

    public function test_checkout_refuses_a_restaurant_without_fssai_or_outside_its_opening_hours(): void
    {
        $this->restaurant->forceFill(['fssai_number' => null])->save();
        $this->assertRefused('not taking orders', fn () => $this->order());
        app(CartService::class)->clearForStore($this->customer, $this->restaurant->id);

        $this->restaurant->forceFill(['fssai_number' => '12345678901234'])->save();
        Carbon::setTestNow(Carbon::parse('2026-11-04 15:00', 'Asia/Kolkata')->utc()); // Wednesday 3 PM local
        StoreHour::create(['store_id' => $this->restaurant->id, 'day' => 3, 'opens_at' => '18:00', 'closes_at' => '23:00']);
        $this->assertRefused('closed right now', fn () => $this->order());

        Carbon::setTestNow(Carbon::parse('2026-11-04 19:00', 'Asia/Kolkata')->utc());
        $this->assertSame('pending', $this->order()->status);
        Carbon::setTestNow();
    }

    public function test_a_sold_out_dish_and_a_broken_choice_rule_are_refused_and_dishes_are_never_counted(): void
    {
        $this->dish->forceFill(['out_of_stock_until' => now()->addHours(3)])->save();
        $this->assertRefused('sold out for today', fn () => $this->order());
        app(CartService::class)->clearForStore($this->customer, $this->restaurant->id);
        $this->dish->forceFill(['out_of_stock_until' => null])->save();

        $group = AddOnGroup::create(['store_id' => $this->restaurant->id, 'name' => 'Chutney', 'min_select' => 1, 'max_select' => 1, 'is_active' => true]);
        $coconut = AddOn::create(['store_id' => $this->restaurant->id, 'add_on_group_id' => $group->id, 'name' => 'Coconut', 'price' => 0, 'is_active' => true]);
        $tomato = AddOn::create(['store_id' => $this->restaurant->id, 'add_on_group_id' => $group->id, 'name' => 'Tomato', 'price' => 5, 'is_active' => true]);
        $dosa = $this->makeProduct($this->restaurant, ['name' => 'Dosa', 'price' => 80, 'stock' => 9999, 'food_type' => 'veg']);
        $dosa->addOnGroups()->sync([$group->id => ['position' => 0]]);

        $this->assertRefused('Chutney: pick 1', fn () => $this->order('cash', 'pickup', [], $dosa, [], 1));
        app(CartService::class)->clearForStore($this->customer, $this->restaurant->id);
        $this->assertRefused('Chutney: at most 1', fn () => $this->order('cash', 'pickup', [], $dosa, [$coconut->id, $tomato->id], 1));
        app(CartService::class)->clearForStore($this->customer, $this->restaurant->id);

        // An add-on from another dish's group (not on this dish) is refused too.
        $other = AddOnGroup::create(['store_id' => $this->restaurant->id, 'name' => 'Extras', 'min_select' => 0, 'max_select' => 2, 'is_active' => true]);
        $raita = AddOn::create(['store_id' => $this->restaurant->id, 'add_on_group_id' => $other->id, 'name' => 'Raita', 'price' => 20, 'is_active' => true]);
        $this->assertRefused('no longer available', fn () => $this->order('cash', 'pickup', [], $dosa, [$tomato->id, $raita->id], 1));
        app(CartService::class)->clearForStore($this->customer, $this->restaurant->id);

        $order = $this->order('cash', 'pickup', [], $dosa, [$tomato->id], 2);
        $this->assertEquals(170, $order->subtotal);
        $this->assertSame('Tomato', $order->items->first()->add_ons_snapshot[0]['name']);
        $this->assertSame(9999, (int) $dosa->fresh()->stock, 'dishes are cooked to order, never counted');

        app(AdminCancelMarketplaceOrderAction::class)->execute($order->id, 'test', 'seller');
        $this->assertSame(9999, (int) $dosa->fresh()->stock, 'and nothing is put back on cancel');
    }

    // ── charges from Food Settings ──────────────────────────────────────

    public function test_packaging_and_gst_follow_food_settings_and_gst_stays_with_the_platform(): void
    {
        $this->restaurant->forceFill(['packaging_charge' => 30])->save();

        $plain = $this->order();
        $this->assertEquals(0, $plain->packaging_charge, 'no limit set = no packaging charge');
        $this->assertEquals(0, $plain->tax_amount, 'GST unset = none, and the restaurant\'s own 18% is not used');
        $this->assertFalse($plain->tax_by_platform);
        $this->assertEquals(200, $plain->total_amount);

        Setting::set('food.packaging_charge_max', '20');
        Setting::set('food.gst_percent', '5');
        $order = $this->order();
        $this->assertEquals(20, $order->packaging_charge, 'capped at the Super Admin limit');
        $this->assertEquals(11, $order->tax_amount, '5% of food 200 + packaging 20');
        $this->assertTrue($order->tax_by_platform);
        $this->assertEquals(231, $order->total_amount);

        // Settle it: the 11 GST never reaches the restaurant's goods value.
        $order->forceFill(['status' => 'completed', 'completed_at' => now(), 'price_final' => 231])->save();
        $settlement = app(MarketplaceSettlementService::class)->recordOnCompletion($order->fresh());
        $this->assertEquals(11, $settlement->platform_gst_amount);
        $this->assertEquals(220, $settlement->goods_amount);
        $this->assertEquals(round(220 - (float) $settlement->goods_platform - (float) $settlement->goods_franchise, 2), (float) $settlement->seller_share + (float) $settlement->tcs_gst_amount + (float) $settlement->tds_income_tax_amount);
        // Cash at pickup: the restaurant holds the 231 and owes the platform's share, its franchise share and the GST.
        $this->assertEquals(round((float) $settlement->goods_platform + (float) $settlement->goods_franchise + 11, 2), (float) $settlement->cash_owed);
    }

    public function test_distance_fee_and_radius_come_from_food_settings_and_unset_keeps_the_flat_fee(): void
    {
        $near = $this->addressAtKm(5);
        $flat = $this->order('cash', 'delivery', ['delivery_address_id' => $near->id]);
        $this->assertEquals(30, $flat->delivery_fee, 'nothing set = the restaurant\'s flat fee');
        $this->assertNull($flat->distance_km);

        Setting::set('food.delivery_fee_base', '25');
        Setting::set('food.delivery_fee_base_km', '2');
        Setting::set('food.delivery_fee_per_km', '8');
        $order = $this->order('cash', 'delivery', ['delivery_address_id' => $near->id]);
        $this->assertEqualsWithDelta(5.0, (float) $order->distance_km, 0.05);
        $this->assertEquals(49, $order->delivery_fee, '25 for 2 km + 3 more km x 8');

        Setting::set('food.delivery_radius_km', '4');
        $this->assertRefused('delivers up to 4 km', fn () => $this->order('cash', 'delivery', ['delivery_address_id' => $near->id]));
    }

    // ── the accept clock ────────────────────────────────────────────────

    public function test_restaurant_must_accept_in_time_or_the_order_is_cancelled_and_refunded_in_full(): void
    {
        $gateway = $this->mock(PaymentGateway::class);
        $gateway->shouldNotReceive('refund');
        $gateway->shouldReceive('identifier')->andReturn('razorpay');
        Setting::create(['key' => 'payment.wallet_enabled', 'value' => '1']);
        Wallet::updateOrCreate(['user_id' => $this->customer->id], ['balance' => 500]);

        $order = $this->order('wallet');
        $this->assertTrue($order->accept_by->between(now()->addMinutes(9)->subSecond(), now()->addMinutes(10)->addSecond()), 'owner decision: 10 minutes');

        $this->travel(9)->minutes();
        $this->artisan('food:expire-unaccepted')->assertSuccessful();
        $this->assertSame('pending', $order->fresh()->status);

        $this->travel(2)->minutes();
        $this->assertRefused('waited too long', fn () => app(AdvanceMarketplaceOrderAction::class)->execute($order->id, 'accepted'));
        $this->artisan('food:expire-unaccepted')->assertSuccessful();

        $fresh = $order->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertEquals(0, $fresh->cancellation_fee);
        $this->assertStringContainsString('did not accept the order within 10 minutes', $fresh->cancellation_note);
        $this->assertEquals(500, (float) Wallet::where('user_id', $this->customer->id)->value('balance'));
    }

    public function test_an_online_order_starts_the_accept_clock_only_when_paid_and_the_time_is_a_setting(): void
    {
        Setting::set('food.accept_minutes', '5');
        $order = $this->order('online');
        $this->assertNull($order->accept_by, 'the restaurant has not seen an unpaid order');

        $this->travel(20)->minutes();
        $payment = Payment::create([
            'marketplace_order_id' => $order->id, 'purpose' => 'marketplace_order', 'amount' => $order->total_amount,
            'gateway' => 'razorpay', 'gateway_order_id' => 'order_'.Str::random(10), 'status' => 'captured',
        ]);
        app(MarketplacePaymentService::class)->markPaid($payment);
        $acceptBy = $order->fresh()->accept_by;
        $this->assertTrue($acceptBy->between(now()->addMinutes(5)->subSecond(), now()->addMinutes(5)->addSecond()));

        $this->travel(2)->minutes();
        \App\Support\FoodOrderClock::startAcceptClock($order->fresh()); // starting again adds no time
        $this->assertTrue($order->fresh()->accept_by->eq($acceptBy));
    }

    // ── cooking, the rider, the customer ────────────────────────────────

    public function test_accepting_promises_a_ready_time_from_the_restaurant_or_its_usual_time_plus_busy(): void
    {
        $this->restaurant->forceFill(['busy_until' => now()->addHour(), 'busy_extra_minutes' => 10])->save();
        $a = $this->order();
        app(AdvanceMarketplaceOrderAction::class)->execute($a->id, 'accepted');
        $this->assertEqualsWithDelta(30, now()->diffInMinutes($a->fresh()->ready_by), 1, 'usual 20 + busy 10');

        $b = $this->order();
        Livewire::actingAs($this->restaurant->provider->user)->test(SellerOrders::class)
            ->assertSee('Accept by')
            ->set("prepTimes.{$b->id}", '45')->call('advance', $b->id, 'accepted')
            ->assertSee('Order accepted. Start cooking.');
        $this->assertEqualsWithDelta(45, now()->diffInMinutes($b->fresh()->ready_by), 1);

        Livewire::actingAs($this->restaurant->provider->user)->test(SellerOrders::class)->set('tab', 'active')
            ->assertSee('Start cooking')->assertSee('You promised it by');
    }

    public function test_rider_is_called_before_ready_only_when_set_and_delivers_only_once_ready(): void
    {
        Queue::fake();
        $rider = $this->makeDeliveryRiderIn($this->franchise, $this->zone, 'food_delivery_rider');
        $order = $this->order('cash', 'delivery', ['delivery_address_id' => $this->addressAtKm(1)->id]);
        app(AdvanceMarketplaceOrderAction::class)->execute($order->id, 'accepted', 15);
        app(AdvanceMarketplaceOrderAction::class)->execute($order->id, 'preparing');

        // Unset = like shops: no rider while cooking.
        $this->travel(14)->minutes();
        $this->artisan('food:call-riders')->assertSuccessful();
        $this->assertNull($order->fresh()->rider_called_at);
        Queue::assertNotPushed(MarketplaceDispatchJob::class);

        // Set to 10 minutes before ready (ready in 1 minute now): called once.
        Setting::set('food.rider_call_before_ready_minutes', '10');
        $this->artisan('food:call-riders')->assertSuccessful();
        $this->artisan('food:call-riders')->assertSuccessful();
        $this->assertNotNull($order->fresh()->rider_called_at);
        Queue::assertPushed(MarketplaceDispatchJob::class, 1);

        // The rider takes it while it cooks, but cannot deliver it until the restaurant marks it ready.
        DispatchAttempt::create([
            'dispatchable_type' => MarketplaceOrder::class, 'dispatchable_id' => $order->id,
            'notifiable_type' => FieldWorker::class, 'notifiable_id' => $rider->id, 'status' => 'notified', 'notified_at' => now(),
        ]);
        app(AcceptMarketplaceDeliveryOfferAction::class)->execute($order->id, $rider);
        $order->refresh();
        $this->assertSame($rider->id, $order->assigned_worker_id);
        $this->assertSame('preparing', $order->status);
        $this->assertRefused('cannot be completed', fn () => app(CompleteMarketplaceOrderAction::class)->execute($order->id, $rider, $order->delivery_otp));

        app(AdvanceMarketplaceOrderAction::class)->execute($order->id, 'ready');
        Queue::assertPushed(MarketplaceDispatchJob::class, 1); // the rider is already on the way: no second search
        app(CompleteMarketplaceOrderAction::class)->execute($order->id, $rider, $order->delivery_otp);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_customer_can_cancel_a_food_order_only_before_the_restaurant_accepts(): void
    {
        $early = $this->order();
        app(AdminCancelMarketplaceOrderAction::class)->execute($early->id, 'Changed my mind', 'customer');
        $this->assertSame('cancelled', $early->fresh()->status);

        $late = $this->order();
        app(AdvanceMarketplaceOrderAction::class)->execute($late->id, 'accepted');
        $this->assertRefused('can no longer be cancelled', fn () => app(AdminCancelMarketplaceOrderAction::class)->execute($late->id, 'Too slow', 'customer'));
        $this->assertSame('accepted', $late->fresh()->status);
    }

    public function test_a_commerce_shop_order_is_unchanged(): void
    {
        $shop = $this->makeStore($this->franchise, $this->zone, null, ['tax_percent' => 10]);
        $this->activateMarketplaceModuleFor($this->franchise);
        $item = $this->makeProduct($shop, ['price' => 100, 'stock' => 10]);
        app(CartService::class)->add($this->customer, $item, 2);
        $order = app(CreateMarketplaceOrderAction::class)->execute([
            'store_id' => $shop->id, 'customer_id' => $this->customer->id, 'order_type' => 'pickup', 'payment_method' => 'cash',
        ]);

        $this->assertEquals(20, $order->tax_amount);
        $this->assertFalse($order->tax_by_platform);
        $this->assertEquals(0, $order->packaging_charge);
        $this->assertNull($order->accept_by);
        $this->assertSame(8, (int) $item->fresh()->stock);
        app(AdvanceMarketplaceOrderAction::class)->execute($order->id, 'accepted');
        $this->assertNull($order->fresh()->ready_by);
        app(AdminCancelMarketplaceOrderAction::class)->execute($order->id, 'Changed my mind', 'customer');
        $this->assertSame(10, (int) $item->fresh()->stock);
    }
}
