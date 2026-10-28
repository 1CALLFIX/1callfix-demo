<?php

namespace Tests\Feature\Marketplace;

use App\Actions\AdminCancelMarketplaceOrderAction;
use App\Actions\AdvanceMarketplaceOrderAction;
use App\Actions\CompleteMarketplaceOrderAction;
use App\Actions\CreateMarketplaceOrderAction;
use App\Contracts\PaymentGateway;
use App\Models\FieldWorker;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceSettlement;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\MarketplaceOrderStatusNotification;
use App\Services\CartService;
use App\Services\DispatchService;
use App\Services\Marketplace\MarketplacePaymentService;
use App\Services\Marketplace\MarketplaceSettlementService;
use App\Services\Payments\RazorpayWebhookHandler;
use App\Services\PayoutService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * E-commerce Phase 0 (2026-10-25) — the four money fixes before any real shop order:
 *  A1 online orders are held from the seller until the Razorpay capture webhook;
 *  A2 cash: never with a benefit; whoever holds the cash owes back what is not theirs;
 *  A3 product discount applied, and a discount (benefit) forces online payment;
 *  A4 seller earnings held for the 3-day return window; rider paid the delivery fee share.
 */
class MarketplacePhase0MoneyTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;

    private function captureWebhook(Payment $payment): void
    {
        app(RazorpayWebhookHandler::class)->handleCaptured(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
            'order_id' => $payment->gateway_order_id, 'id' => 'pay_'.Str::random(8), 'currency' => 'INR',
            'amount' => (int) round((float) $payment->amount * 100),
        ]]]]);
    }

    private function pendingOnlinePayment(MarketplaceOrder $order): Payment
    {
        return Payment::create([
            'marketplace_order_id' => $order->id, 'purpose' => 'marketplace_order', 'amount' => $order->total_amount,
            'gateway' => 'razorpay', 'gateway_order_id' => 'order_'.Str::random(10), 'status' => 'pending',
        ]);
    }

    /** A live store with one ₹100 product, module on. */
    private function shop(array $productOverrides = []): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateMarketplaceModuleFor($franchise);
        $customer = $this->makeCustomer();
        $owner = $this->makeStoreOwner($franchise, $zone);
        $store = $this->makeStore($franchise, $zone, $owner);
        $product = $this->makeProduct($store, array_merge(['price' => 100, 'stock' => 10], $productOverrides));

        return compact('franchise', 'zone', 'customer', 'owner', 'store', 'product');
    }

    private function checkout(array $w, string $paymentMethod, array $extra = []): MarketplaceOrder
    {
        app(CartService::class)->add($w['customer'], $w['product'], 2);

        return app(CreateMarketplaceOrderAction::class)->execute(array_merge([
            'store_id' => $w['store']->id, 'customer_id' => $w['customer']->id, 'order_type' => 'pickup',
            'payment_method' => $paymentMethod,
        ], $extra));
    }

    /**
     * Franchise at 20% platform fee + 10% revenue share, with an owner. A completed delivery order: goods 1000,
     * delivery fee 50, rider assigned. Goods split 200/100/700, delivery split 10/5/35.
     */
    private function deliveredOrder(string $paymentMethod, float $deliveryFee = 50): array
    {
        $scenario = $this->makeMarketplaceOrderScenario('ready', [
            'order_type' => 'delivery', 'subtotal' => 1000, 'delivery_fee' => $deliveryFee, 'total_amount' => 1000 + $deliveryFee,
            'payment_method' => $paymentMethod, 'payment_status' => $paymentMethod === 'cash' ? 'pending' : 'paid',
        ]);
        $franchiseOwner = $this->makeCustomer();
        $scenario['franchise']->update(['platform_fee_percent' => 20, 'commission_model' => 'revenue_share', 'commission_value' => 10, 'owner_user_id' => $franchiseOwner->id]);
        $rider = $this->makeDeliveryRiderIn($scenario['franchise'], $scenario['zone']);
        $scenario['order']->update(['assigned_worker_id' => $rider->id, 'delivery_otp' => '4321']);

        app(CompleteMarketplaceOrderAction::class)->execute($scenario['order']->id, $rider, '4321');

        return $scenario + ['rider' => $rider, 'franchiseOwner' => $franchiseOwner];
    }

    private function balance(?User $user): float
    {
        return app(WalletService::class)->balance($user);
    }

    // ═══════════════════════ A1 — online payment ═══════════════════════

    public function test_an_online_order_is_held_unpaid_and_the_seller_cannot_accept_it(): void
    {
        Notification::fake();
        $w = $this->shop();

        $order = $this->checkout($w, 'online');

        $this->assertTrue($order->isAwaitingOnlinePayment());
        $this->assertSame('pending', $order->payment_status);
        Notification::assertNotSentTo($w['customer'], MarketplaceOrderStatusNotification::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not paid yet');
        app(AdvanceMarketplaceOrderAction::class)->execute($order->id, 'accepted');
    }

    public function test_start_online_payment_opens_a_gateway_order_and_a_pending_payment_row(): void
    {
        $gateway = $this->mock(PaymentGateway::class);
        $gateway->shouldReceive('isConfigured')->andReturnTrue();
        $gateway->shouldReceive('identifier')->andReturn('razorpay');
        $gateway->shouldReceive('createRawOrder')->once()->andReturn(['razorpay_order_id' => 'order_M1', 'amount' => 20000, 'currency' => 'INR', 'key_id' => 'rzp_test']);

        $w = $this->shop();
        $order = $this->checkout($w, 'online');

        $checkout = app(MarketplacePaymentService::class)->startOnlinePayment($order);

        $this->assertSame('order_M1', $checkout['razorpay_order_id']);
        $this->assertDatabaseHas('payments', ['marketplace_order_id' => $order->id, 'purpose' => 'marketplace_order', 'gateway_order_id' => 'order_M1', 'status' => 'pending']);
    }

    public function test_the_pay_endpoint_is_owner_only(): void
    {
        $w = $this->shop();
        $order = $this->checkout($w, 'online');

        $this->actingAs($this->makeCustomer(), 'sanctum')
            ->postJson("/api/marketplace-orders/{$order->id}/pay")
            ->assertNotFound();
    }

    public function test_capture_webhook_marks_the_order_paid_and_the_seller_can_accept(): void
    {
        Notification::fake();
        $w = $this->shop();
        $order = $this->checkout($w, 'online');

        $this->captureWebhook($this->pendingOnlinePayment($order));

        $this->assertSame('paid', $order->fresh()->payment_status);
        Notification::assertSentTo($w['customer'], MarketplaceOrderStatusNotification::class);
        $this->assertSame('accepted', app(AdvanceMarketplaceOrderAction::class)->execute($order->id, 'accepted')->status);
    }

    public function test_a_payment_captured_after_the_order_was_cancelled_is_refunded(): void
    {
        $gateway = $this->mock(PaymentGateway::class);
        $gateway->shouldReceive('refund')->once()->withArgs(fn ($id, $amount) => (float) $amount === 200.0)->andReturn(['id' => 'rfnd_1']);
        $gateway->shouldReceive('identifier')->andReturn('razorpay');

        $w = $this->shop();
        $order = $this->checkout($w, 'online');
        $payment = $this->pendingOnlinePayment($order);
        app(AdminCancelMarketplaceOrderAction::class)->execute($order->id, 'Customer changed mind');

        $this->captureWebhook($payment);

        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('refunded', $order->fresh()->payment_status);
    }

    public function test_a_late_payment_after_unpaid_expiry_is_refunded_in_full_even_with_a_cancellation_fee_set(): void
    {
        Setting::create(['key' => 'cancellation.free_minutes', 'value' => '0']);
        Setting::create(['key' => 'cancellation.fee_type', 'value' => 'flat']);
        Setting::create(['key' => 'cancellation.fee_value', 'value' => '50']);
        $gateway = $this->mock(PaymentGateway::class);
        $gateway->shouldReceive('refund')->once()->withArgs(fn ($id, $amount) => (float) $amount === 200.0)->andReturn(['id' => 'rfnd_2']);
        $gateway->shouldReceive('identifier')->andReturn('razorpay');

        $w = $this->shop();
        $order = $this->checkout($w, 'online');
        $payment = $this->pendingOnlinePayment($order);

        $this->travel(31)->minutes();
        $this->artisan('marketplace:expire-unpaid')->assertSuccessful();
        $this->assertEquals(0, $order->fresh()->cancellation_fee, 'The seller never saw it: no fee.');

        $this->captureWebhook($payment);

        $this->assertSame('refunded', $payment->fresh()->status);
    }

    public function test_a_replayed_capture_webhook_marks_the_order_paid_once_and_refunds_nothing(): void
    {
        Notification::fake();
        $gateway = $this->mock(PaymentGateway::class);
        $gateway->shouldNotReceive('refund');
        $gateway->shouldReceive('identifier')->andReturn('razorpay');

        $w = $this->shop();
        $order = $this->checkout($w, 'online');
        $payment = $this->pendingOnlinePayment($order);

        $this->captureWebhook($payment);
        $this->captureWebhook($payment->fresh()); // Razorpay retries the same event

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('captured', $payment->fresh()->status);
        $this->assertSame(1, $order->statusHistory()->where('note', 'Payment received online')->count());
        Notification::assertSentToTimes($w['customer'], MarketplaceOrderStatusNotification::class, 1);
    }

    public function test_a_second_payment_for_an_already_paid_order_is_refunded_in_full(): void
    {
        Setting::create(['key' => 'cancellation.free_minutes', 'value' => '0']);
        Setting::create(['key' => 'cancellation.fee_type', 'value' => 'flat']);
        Setting::create(['key' => 'cancellation.fee_value', 'value' => '50']);
        $gateway = $this->mock(PaymentGateway::class);
        $gateway->shouldReceive('refund')->once()->withArgs(fn ($id, $amount) => (float) $amount === 200.0)->andReturn(['id' => 'rfnd_dup']);
        $gateway->shouldReceive('identifier')->andReturn('razorpay');

        $w = $this->shop();
        $order = $this->checkout($w, 'online');
        // The customer tapped Pay twice and both went through.
        $first = $this->pendingOnlinePayment($order);
        $second = $this->pendingOnlinePayment($order);

        $this->captureWebhook($first);
        $this->captureWebhook($second);
        $this->captureWebhook($second->fresh()); // and the duplicate's webhook is replayed

        $this->assertSame('captured', $first->fresh()->status);
        $this->assertSame('refunded', $second->fresh()->status);
        $this->assertEquals(200, $second->fresh()->refunded_amount, 'No cancellation fee on a duplicate.');
        $this->assertSame('paid', $order->fresh()->payment_status, 'The order stays paid by the first payment.');
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_a_duplicate_payment_after_the_order_was_cancelled_is_refunded_in_full_too(): void
    {
        $gateway = $this->mock(PaymentGateway::class);
        $gateway->shouldReceive('refund')->twice()->withArgs(fn ($id, $amount) => (float) $amount === 200.0)->andReturn(['id' => 'rfnd_x']);
        $gateway->shouldReceive('identifier')->andReturn('razorpay');

        $w = $this->shop();
        $order = $this->checkout($w, 'online');
        $first = $this->pendingOnlinePayment($order);
        $second = $this->pendingOnlinePayment($order);

        $this->travel(31)->minutes();
        $this->artisan('marketplace:expire-unpaid')->assertSuccessful();
        $this->captureWebhook($first);
        $this->captureWebhook($second);

        $this->assertSame('refunded', $first->fresh()->status);
        $this->assertSame('refunded', $second->fresh()->status);
        $this->assertSame('refunded', $order->fresh()->payment_status);
    }

    public function test_a_wallet_paid_order_cancelled_before_the_seller_accepts_is_refunded_to_the_wallet_in_full_once(): void
    {
        $gateway = $this->mock(PaymentGateway::class);
        $gateway->shouldNotReceive('refund');
        $gateway->shouldReceive('identifier')->andReturn('razorpay');
        Setting::create(['key' => 'payment.wallet_enabled', 'value' => '1']);
        Setting::create(['key' => 'cancellation.free_minutes', 'value' => '5']);
        Setting::create(['key' => 'cancellation.fee_type', 'value' => 'flat']);
        Setting::create(['key' => 'cancellation.fee_value', 'value' => '50']);

        $w = $this->shop();
        Wallet::updateOrCreate(['user_id' => $w['customer']->id], ['balance' => 500]);
        $order = $this->checkout($w, 'wallet');
        $this->assertSame(300.0, $this->balance($w['customer']));

        app(AdminCancelMarketplaceOrderAction::class)->execute($order->id, 'Seller out of stock');
        // A second refund attempt (retry / repeated admin action) must not pay twice.
        app(\App\Services\CancellationService::class)->refundIfPaidForMarketplaceOrder($order->fresh(), 0);
        try {
            app(AdminCancelMarketplaceOrderAction::class)->execute($order->id, 'again');
        } catch (\RuntimeException) {
        }

        $this->assertSame(500.0, $this->balance($w['customer']), 'Full 200 back, once.');
        $this->assertEquals(0, $order->fresh()->cancellation_fee);
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame(1, \App\Models\WalletTransaction::where('ref', "marketplace_order:{$order->id}:wallet-refund")->count());
    }

    public function test_unpaid_online_orders_expire_and_their_stock_comes_back(): void
    {
        $w = $this->shop();
        $order = $this->checkout($w, 'online');
        $this->assertSame(8, $w['product']->fresh()->stock);

        $this->travel(29)->minutes();
        $this->artisan('marketplace:expire-unpaid')->assertSuccessful();
        $this->assertSame('pending', $order->fresh()->status, 'Not yet past the 30-minute default.');

        $this->travel(2)->minutes();
        $this->artisan('marketplace:expire-unpaid')->assertSuccessful();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, $w['product']->fresh()->stock);
    }

    public function test_the_expiry_window_is_an_admin_setting(): void
    {
        Setting::create(['key' => 'marketplace.unpaid_expiry_minutes', 'value' => '10']);
        $w = $this->shop();
        $order = $this->checkout($w, 'online');

        $this->travel(11)->minutes();
        $this->artisan('marketplace:expire-unpaid')->assertSuccessful();

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    // ═══════════════════════ A2/A3 — benefits and cash ═══════════════════════

    public function test_the_product_discount_is_recorded_once_and_tax_is_charged_on_the_discounted_price(): void
    {
        $w = $this->shop(['discount_percent' => 10]);
        $w['store']->update(['tax_percent' => 5]);

        $order = $this->checkout($w, 'online');

        $this->assertSame(180.0, (float) $order->subtotal, 'Cart price already has the 10% off (2 x 90).');
        $this->assertSame(20.0, (float) $order->discount_amount, 'Recorded, not taken off a second time.');
        $this->assertTrue($order->usesBenefit());
        $this->assertSame(9.0, (float) $order->tax_amount); // 5% of 180
        $this->assertSame(189.0, (float) $order->total_amount);
    }

    public function test_cash_is_refused_for_a_discounted_order(): void
    {
        $w = $this->shop(['discount_percent' => 10]);

        try {
            $this->checkout($w, 'cash');
            $this->fail('A discounted order must never be paid in cash.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('must be paid online', $e->getMessage());
        }

        $this->assertSame(0, MarketplaceOrder::count());
        $this->assertSame(10, $w['product']->fresh()->stock, 'Nothing reserved for a refused order.');
    }

    public function test_cash_is_refused_for_a_discounted_order_through_the_api(): void
    {
        $w = $this->shop(['discount_percent' => 10]);
        app(CartService::class)->add($w['customer'], $w['product'], 1);

        $this->actingAs($w['customer'], 'sanctum')
            ->postJson('/api/marketplace-orders', ['store_id' => $w['store']->id, 'order_type' => 'pickup', 'payment_method' => 'cash'])
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'Discounted items must be paid online. Please choose online payment or wallet.']);
    }

    public function test_a_coupon_code_is_refused_for_every_payment_method(): void
    {
        foreach (['online', 'wallet', 'cash'] as $method) {
            $w = $this->shop();
            try {
                $this->checkout($w, $method, ['coupon_code' => 'SAVE10']);
                $this->fail("A coupon must be refused for {$method}.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Coupons are not available', $e->getMessage());
            }
        }
    }

    public function test_a_discounted_order_can_be_paid_from_the_wallet(): void
    {
        $w = $this->shop(['discount_percent' => 10]);
        Setting::create(['key' => 'payment.wallet_enabled', 'value' => '1']);
        Wallet::updateOrCreate(['user_id' => $w['customer']->id], ['balance' => 500]);

        $order = $this->checkout($w, 'wallet');

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(320.0, $this->balance($w['customer'])); // 500 - 180
    }

    public function test_a_no_benefit_cash_order_is_accepted_straight_away(): void
    {
        $w = $this->shop();
        $order = $this->checkout($w, 'cash');

        $this->assertFalse($order->isAwaitingOnlinePayment());
        $this->assertSame('accepted', app(AdvanceMarketplaceOrderAction::class)->execute($order->id, 'accepted')->status);
    }

    // ═══════════════════════ A4 — settlement ═══════════════════════

    public function test_online_delivery_pays_the_rider_now_and_holds_the_seller_for_the_return_window(): void
    {
        $s = $this->deliveredOrder('online');

        $row = MarketplaceSettlement::where('marketplace_order_id', $s['order']->id)->firstOrFail();
        $this->assertSame('online', $row->payment_mode);
        $this->assertEquals(700, $row->seller_share);
        $this->assertEquals(35, $row->rider_share);

        $this->assertSame(35.0, $this->balance($s['rider']->user), 'Rider: 50 delivery fee - 10 platform - 5 franchise.');
        $this->assertSame(5.0, $this->balance($s['franchiseOwner']), 'Franchise: delivery share now, goods share later.');
        $this->assertSame(0.0, $this->balance($s['owner']->user), 'Seller held for the return window.');

        $this->travel(2)->days();
        $this->artisan('marketplace:release-earnings')->assertSuccessful();
        $this->assertSame(0.0, $this->balance($s['owner']->user));

        $this->travel(1)->days();
        $this->artisan('marketplace:release-earnings')->assertSuccessful();
        $this->assertSame(700.0, $this->balance($s['owner']->user));
        $this->assertSame(105.0, $this->balance($s['franchiseOwner']));

        $this->artisan('marketplace:release-earnings')->assertSuccessful();
        $this->assertSame(700.0, $this->balance($s['owner']->user), 'Released once only.');
    }

    public function test_free_delivery_without_a_minimum_payout_pays_the_rider_nothing(): void
    {
        $s = $this->deliveredOrder('online', 0);

        $row = MarketplaceSettlement::where('marketplace_order_id', $s['order']->id)->firstOrFail();
        $this->assertEquals(0, $row->rider_share);
        $this->assertEquals(0, $row->rider_topup);
        $this->assertSame(0.0, $this->balance($s['rider']->user));
    }

    public function test_free_delivery_pays_the_rider_the_minimum_funded_by_the_platform(): void
    {
        Setting::create(['key' => 'marketplace.rider_min_payout', 'value' => '30']);
        $s = $this->deliveredOrder('online', 0);

        $row = MarketplaceSettlement::where('marketplace_order_id', $s['order']->id)->firstOrFail();
        $this->assertEquals(30, $row->rider_share);
        $this->assertEquals(30, $row->rider_topup);
        $this->assertSame('platform', $row->rider_topup_funded_by);
        $this->assertEquals(700, $row->seller_share, 'The seller is not touched.');
        $this->assertEquals(170, $row->commission->platform_commission, '200 goods commission - 30 paid to the rider.');
        $this->assertSame(30.0, $this->balance($s['rider']->user), 'Paid at delivery.');

        $this->travel(3)->days();
        $this->artisan('marketplace:release-earnings')->assertSuccessful();
        $this->assertSame(700.0, $this->balance($s['owner']->user));
    }

    public function test_the_minimum_payout_can_be_funded_by_the_seller(): void
    {
        Setting::create(['key' => 'marketplace.rider_min_payout', 'value' => '30']);
        Setting::create(['key' => 'marketplace.rider_min_payout_funded_by', 'value' => 'seller']);
        $s = $this->deliveredOrder('online', 0);

        $row = MarketplaceSettlement::where('marketplace_order_id', $s['order']->id)->firstOrFail();
        $this->assertEquals(30, $row->rider_share);
        $this->assertSame('seller', $row->rider_topup_funded_by);
        $this->assertEquals(670, $row->seller_share);
        $this->assertEquals(200, $row->commission->platform_commission);

        $this->travel(3)->days();
        $this->artisan('marketplace:release-earnings')->assertSuccessful();
        $this->assertSame(670.0, $this->balance($s['owner']->user));
    }

    public function test_the_minimum_payout_only_tops_up_and_never_cuts_a_bigger_share(): void
    {
        Setting::create(['key' => 'marketplace.rider_min_payout', 'value' => '30']);
        $s = $this->deliveredOrder('online'); // fee 50 -> rider share 35, already above 30

        $row = MarketplaceSettlement::where('marketplace_order_id', $s['order']->id)->firstOrFail();
        $this->assertEquals(35, $row->rider_share);
        $this->assertEquals(0, $row->rider_topup);
        $this->assertNull($row->rider_topup_funded_by);
    }

    public function test_free_delivery_cash_order_lets_the_rider_keep_the_minimum(): void
    {
        Setting::create(['key' => 'marketplace.rider_min_payout', 'value' => '30']);
        $s = $this->deliveredOrder('cash', 0);

        $row = MarketplaceSettlement::where('marketplace_order_id', $s['order']->id)->firstOrFail();
        $this->assertEquals(970, $row->cash_owed, 'Collected 1000, keeps the 30 minimum.');
        $this->assertSame(0.0, $this->balance($s['rider']->user));
    }

    public function test_free_delivery_is_a_benefit_so_a_cash_order_above_the_threshold_pays_the_delivery_fee(): void
    {
        $w = $this->shop();
        $w['store']->update(['delivery_fee_flat' => 40, 'free_delivery_above_amount' => 150]);
        $address = $this->makeAddress($w['customer'], $w['franchise'], $w['zone']);

        $order = $this->checkout($w, 'cash', ['order_type' => 'delivery', 'delivery_address_id' => $address->id]);

        $this->assertEquals(40, $order->delivery_fee, 'Cash gets no benefit: subtotal 200 is above 150, fee still charged.');
        $this->assertEquals(240, $order->total_amount);
    }

    public function test_an_online_order_above_the_threshold_gets_free_delivery(): void
    {
        $w = $this->shop();
        $w['store']->update(['delivery_fee_flat' => 40, 'free_delivery_above_amount' => 150]);
        $address = $this->makeAddress($w['customer'], $w['franchise'], $w['zone']);

        $order = $this->checkout($w, 'online', ['order_type' => 'delivery', 'delivery_address_id' => $address->id]);

        $this->assertEquals(0, $order->delivery_fee);
        $this->assertEquals(200, $order->total_amount);
    }

    public function test_a_pickup_order_has_no_delivery_fee_no_rider_pay_and_no_delivery_commission(): void
    {
        Setting::create(['key' => 'marketplace.rider_min_payout', 'value' => '30']);
        $w = $this->shop();
        $w['store']->update(['delivery_fee_flat' => 40, 'free_delivery_above_amount' => null]);

        $order = $this->checkout($w, 'cash');
        $this->assertEquals(0, $order->delivery_fee, 'Pickup is never charged a delivery fee.');

        // Even with a fee written on the order by mistake, no rider = no rider pay and no delivery split.
        $scenario = $this->makeMarketplaceOrderScenario('ready', [
            'order_type' => 'pickup', 'subtotal' => 1000, 'delivery_fee' => 40, 'total_amount' => 1040,
        ]);
        $scenario['franchise']->update(['platform_fee_percent' => 20, 'commission_model' => 'revenue_share', 'commission_value' => 10]);
        app(CompleteMarketplaceOrderAction::class)->execute($scenario['order']->id);

        $row = MarketplaceSettlement::where('marketplace_order_id', $scenario['order']->id)->firstOrFail();
        $this->assertNull($row->field_worker_id);
        $this->assertEquals(0, $row->delivery_amount);
        $this->assertEquals(0, $row->rider_share);
        $this->assertEquals(0, $row->rider_topup);
        $this->assertEquals(0, $row->delivery_platform);
        $this->assertEquals(0, $row->delivery_franchise);
        $this->assertEquals(1040, $row->goods_amount, 'The whole amount is goods money.');
    }

    public function test_the_return_window_is_an_admin_setting(): void
    {
        Setting::create(['key' => 'marketplace.return_window_days', 'value' => '1']);
        $s = $this->deliveredOrder('online');

        $this->travel(1)->days();
        $this->artisan('marketplace:release-earnings')->assertSuccessful();

        $this->assertSame(700.0, $this->balance($s['owner']->user));
    }

    public function test_cash_on_delivery_makes_the_rider_owe_everything_but_their_own_share(): void
    {
        $s = $this->deliveredOrder('cash');

        $row = MarketplaceSettlement::where('marketplace_order_id', $s['order']->id)->firstOrFail();
        $this->assertSame('cash_delivery', $row->payment_mode);
        $this->assertSame('field_worker', $row->cash_debtor_type);
        $this->assertEquals(1015, $row->cash_owed, 'Collected 1050, keeps 35.');
        $this->assertSame(0.0, $this->balance($s['rider']->user), 'Nothing credited: the rider holds the cash.');
        $this->assertSame(0.0, $this->balance($s['franchiseOwner']));

        // Past the window, but the rider has not paid the cash in: the seller still waits.
        $this->travel(3)->days();
        $this->artisan('marketplace:release-earnings')->assertSuccessful();
        $this->assertSame(0.0, $this->balance($s['owner']->user));
        $this->assertSame('held', $row->fresh()->status);

        // The rider deposits the cash into their wallet; the next run recovers it and pays everyone.
        Wallet::updateOrCreate(['user_id' => $s['rider']->user_id], ['balance' => 1015]);
        $this->artisan('marketplace:release-earnings')->assertSuccessful();

        $this->assertSame(0.0, $this->balance($s['rider']->user));
        $this->assertSame('settled', $row->fresh()->cash_status);
        $this->assertSame(700.0, $this->balance($s['owner']->user));
        $this->assertSame(105.0, $this->balance($s['franchiseOwner']), 'Franchise: 100 goods + 5 delivery, paid from the recovered cash.');
    }

    public function test_a_rider_payout_is_refused_while_shop_cash_is_owed(): void
    {
        $s = $this->deliveredOrder('cash');
        Wallet::updateOrCreate(['user_id' => $s['rider']->user_id], ['balance' => 15]);

        try {
            app(PayoutService::class)->request('field_worker', $s['rider']->id, 10);
            $this->fail('A payout must be refused while shop cash is owed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Shop order cash', $e->getMessage());
        }

        $this->assertSame(1000.0, app(MarketplaceSettlementService::class)->outstanding('field_worker', $s['rider']->id));
    }

    public function test_cash_at_pickup_makes_the_seller_owe_the_commission(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario('ready', [
            'order_type' => 'pickup', 'subtotal' => 1000, 'total_amount' => 1000, 'payment_method' => 'cash', 'payment_status' => 'pending',
        ]);
        $scenario['franchise']->update(['platform_fee_percent' => 20, 'commission_model' => 'revenue_share', 'commission_value' => 10]);

        app(CompleteMarketplaceOrderAction::class)->execute($scenario['order']->id);

        $row = MarketplaceSettlement::where('marketplace_order_id', $scenario['order']->id)->firstOrFail();
        $this->assertSame('cash_pickup', $row->payment_mode);
        $this->assertSame('provider', $row->cash_debtor_type);
        $this->assertEquals(300, $row->cash_owed);
        $this->assertSame('released', $row->status, 'The seller already holds their share.');
        $this->assertSame(0.0, $this->balance($scenario['owner']->user));

        Wallet::updateOrCreate(['user_id' => $scenario['owner']->user_id], ['balance' => 100]);
        try {
            app(PayoutService::class)->request('provider', $scenario['owner']->id, 10);
            $this->fail('A seller payout must be refused while shop cash is owed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Shop order cash', $e->getMessage());
        }
        $this->assertSame(200.0, app(MarketplaceSettlementService::class)->outstanding('provider', $scenario['owner']->id));
    }

    public function test_online_rider_earnings_clear_older_shop_cash_first(): void
    {
        $cash = $this->deliveredOrder('cash');
        $rider = $cash['rider'];

        // A second, online delivery for the same rider: its 35 goes against the 1015 they owe.
        $online = $this->makeMarketplaceOrderScenario('ready', ['order_type' => 'delivery', 'subtotal' => 1000, 'delivery_fee' => 50, 'total_amount' => 1050]);
        $online['franchise']->update(['platform_fee_percent' => 20, 'commission_model' => 'revenue_share', 'commission_value' => 10]);
        $online['order']->update(['assigned_worker_id' => $rider->id, 'delivery_otp' => '1111', 'zone_id' => $rider->zone_id]);
        app(CompleteMarketplaceOrderAction::class)->execute($online['order']->id, $rider, '1111');

        $this->assertSame(0.0, $this->balance($rider->user));
        $this->assertSame(980.0, app(MarketplaceSettlementService::class)->outstanding('field_worker', $rider->id));
    }

    public function test_a_rider_over_the_cash_limit_gets_no_cash_shop_orders_but_still_gets_prepaid_ones(): void
    {
        $s = $this->deliveredOrder('cash'); // rider now owes 1015
        Setting::create(['key' => 'parcel.rider_cash_due_limit', 'value' => '500']);
        $s['rider']->update(['location_updated_at' => now()]);
        $s['store']->update(['lat' => 1.0, 'lng' => 1.0]);

        $make = fn (string $method) => MarketplaceOrder::create([
            'code' => 'MTST-'.Str::random(8), 'franchise_id' => $s['franchise']->id, 'zone_id' => $s['zone']->id,
            'customer_id' => $s['customer']->id, 'store_id' => $s['store']->id, 'module' => $s['store']->module,
            'order_type' => 'delivery', 'subtotal' => 100, 'total_amount' => 100, 'status' => 'ready',
            'payment_status' => $method === 'cash' ? 'pending' : 'paid', 'payment_method' => $method,
        ]);

        $ids = fn (MarketplaceOrder $o) => app(DispatchService::class)->findMarketplaceDeliveryRiderCandidates($o)
            ->map(fn ($c) => $c['provider']->id)->all();

        $this->assertNotContains($s['rider']->id, $ids($make('cash')));
        $this->assertContains($s['rider']->id, $ids($make('online')));
    }
}
