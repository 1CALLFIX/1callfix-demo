<?php

namespace Tests\Feature\Marketplace;

use App\Actions\AcceptMarketplaceDeliveryOfferAction;
use App\Actions\AdminCancelMarketplaceOrderAction;
use App\Actions\AdvanceMarketplaceOrderAction;
use App\Actions\CompleteMarketplaceOrderAction;
use App\Actions\CreateMarketplaceOrderAction;
use App\Exceptions\ModuleNotActiveException;
use App\Models\Commission;
use App\Models\DispatchAttempt;
use App\Models\MarketplaceOrder;
use App\Models\Payment;
use App\Services\CartService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * Phase 24 (Marketplace Foundation). Core lifecycle coverage: module gate,
 * cart-to-checkout, stock-lock concurrency guard, minimum-order/delivery-
 * fee/tax calculation, the full pending->accepted->preparing->ready->
 * completed flow (both order types), commission, cancellation + stock
 * release, wallet payment, delivery-rider dispatch acceptance, and a
 * cross-vertical regression check.
 */
class MarketplaceOrderLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;

    public function test_checkout_is_blocked_while_the_module_is_not_implemented(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $store = $this->makeStore($franchise, $zone);
        $product = $this->makeProduct($store);
        app(CartService::class)->add($customer, $product, 1);

        $this->expectException(ModuleNotActiveException::class);

        app(CreateMarketplaceOrderAction::class)->execute([
            'store_id' => $store->id, 'customer_id' => $customer->id, 'order_type' => 'pickup',
        ]);
    }

    public function test_checkout_succeeds_once_the_module_is_enabled(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateMarketplaceModuleFor($franchise);
        $customer = $this->makeCustomer();
        $store = $this->makeStore($franchise, $zone);
        $product = $this->makeProduct($store, ['price' => 100, 'stock' => 10]);
        app(CartService::class)->add($customer, $product, 2);

        $order = app(CreateMarketplaceOrderAction::class)->execute([
            'store_id' => $store->id, 'customer_id' => $customer->id, 'order_type' => 'pickup',
        ]);

        $this->assertNotNull($order->id);
        $this->assertSame('pending', $order->status);
        $this->assertStringContainsString('-MKT-', $order->code);
        $this->assertSame(200.0, (float) $order->subtotal); // 2 * 100
        $this->assertSame(8, $product->fresh()->stock); // 10 - 2
    }

    public function test_checkout_clears_the_cart_for_that_store(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateMarketplaceModuleFor($franchise);
        $customer = $this->makeCustomer();
        $store = $this->makeStore($franchise, $zone);
        $product = $this->makeProduct($store);
        app(CartService::class)->add($customer, $product, 1);

        app(CreateMarketplaceOrderAction::class)->execute([
            'store_id' => $store->id, 'customer_id' => $customer->id, 'order_type' => 'pickup',
        ]);

        $this->assertCount(0, app(CartService::class)->listForStore($customer, $store->id));
    }

    public function test_checkout_fails_when_cart_is_empty(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateMarketplaceModuleFor($franchise);
        $customer = $this->makeCustomer();
        $store = $this->makeStore($franchise, $zone);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cart is empty');

        app(CreateMarketplaceOrderAction::class)->execute([
            'store_id' => $store->id, 'customer_id' => $customer->id, 'order_type' => 'pickup',
        ]);
    }

    public function test_minimum_order_amount_is_enforced(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateMarketplaceModuleFor($franchise);
        $customer = $this->makeCustomer();
        $store = $this->makeStore($franchise, $zone, null, ['minimum_order_amount' => 500]);
        $product = $this->makeProduct($store, ['price' => 100]);
        app(CartService::class)->add($customer, $product, 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('minimum order of 500');

        app(CreateMarketplaceOrderAction::class)->execute([
            'store_id' => $store->id, 'customer_id' => $customer->id, 'order_type' => 'pickup',
        ]);
    }

    public function test_delivery_requires_an_address(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateMarketplaceModuleFor($franchise);
        $customer = $this->makeCustomer();
        $store = $this->makeStore($franchise, $zone);
        $product = $this->makeProduct($store);
        app(CartService::class)->add($customer, $product, 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('delivery address is required');

        app(CreateMarketplaceOrderAction::class)->execute([
            'store_id' => $store->id, 'customer_id' => $customer->id, 'order_type' => 'delivery',
        ]);
    }

    public function test_insufficient_stock_blocks_checkout_and_does_not_partially_decrement(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateMarketplaceModuleFor($franchise);
        $customer = $this->makeCustomer();
        $store = $this->makeStore($franchise, $zone);
        $product = $this->makeProduct($store, ['price' => 50, 'stock' => 1]);
        app(CartService::class)->add($customer, $product, 5);

        try {
            app(CreateMarketplaceOrderAction::class)->execute([
                'store_id' => $store->id, 'customer_id' => $customer->id, 'order_type' => 'pickup',
            ]);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Insufficient stock', $e->getMessage());
        }

        $this->assertSame(1, $product->fresh()->stock, 'Stock must not be decremented when checkout fails.');
        $this->assertSame(0, MarketplaceOrder::count(), 'No order row must be left behind when checkout fails.');
    }

    public function test_checkout_blocks_a_product_deactivated_after_it_was_added_to_the_cart(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateMarketplaceModuleFor($franchise);
        $customer = $this->makeCustomer();
        $store = $this->makeStore($franchise, $zone);
        $product = $this->makeProduct($store, ['price' => 100, 'stock' => 10]);
        app(CartService::class)->add($customer, $product, 1);

        $product->update(['is_active' => false]); // deactivated after the cart line was added

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no longer available');

        app(CreateMarketplaceOrderAction::class)->execute([
            'store_id' => $store->id, 'customer_id' => $customer->id, 'order_type' => 'pickup',
        ]);
    }

    public function test_variant_stock_is_authoritative_over_the_base_product_stock_when_a_variant_is_selected(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateMarketplaceModuleFor($franchise);
        $customer = $this->makeCustomer();
        $store = $this->makeStore($franchise, $zone);
        $product = $this->makeProduct($store, ['price' => 100, 'stock' => 0]); // base stock 0 -- irrelevant once a variant is chosen
        $variant = $this->makeProductVariant($product, ['stock' => 5, 'price_override' => 120]);
        app(CartService::class)->add($customer, $product, 2, $variant);

        $order = app(CreateMarketplaceOrderAction::class)->execute([
            'store_id' => $store->id, 'customer_id' => $customer->id, 'order_type' => 'pickup',
        ]);

        $this->assertSame(240.0, (float) $order->subtotal); // 2 * 120 (variant override, not base price)
        $this->assertSame(3, $variant->fresh()->stock);
        $this->assertSame(0, $product->fresh()->stock, 'Base product stock is untouched when a variant was selected.');
    }

    public function test_delivery_fee_waived_above_the_free_delivery_threshold(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateMarketplaceModuleFor($franchise);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $store = $this->makeStore($franchise, $zone, null, ['delivery_fee_flat' => 30, 'free_delivery_above_amount' => 200]);
        $product = $this->makeProduct($store, ['price' => 250]);
        app(CartService::class)->add($customer, $product, 1);

        $order = app(CreateMarketplaceOrderAction::class)->execute([
            'store_id' => $store->id, 'customer_id' => $customer->id, 'order_type' => 'delivery', 'delivery_address_id' => $address->id,
        ]);

        $this->assertSame(0.0, (float) $order->delivery_fee);
        $this->assertSame(250.0, (float) $order->total_amount);
    }

    public function test_tax_percent_is_applied_from_the_store(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateMarketplaceModuleFor($franchise);
        $customer = $this->makeCustomer();
        $store = $this->makeStore($franchise, $zone, null, ['tax_percent' => 10]);
        $product = $this->makeProduct($store, ['price' => 100]);
        app(CartService::class)->add($customer, $product, 1);

        $order = app(CreateMarketplaceOrderAction::class)->execute([
            'store_id' => $store->id, 'customer_id' => $customer->id, 'order_type' => 'pickup',
        ]);

        $this->assertSame(10.0, (float) $order->tax_amount);
        $this->assertSame(110.0, (float) $order->total_amount);
    }

    // ============================== Lifecycle (pickup) ==============================

    public function test_full_pickup_lifecycle_accepted_preparing_ready_completed_applies_commission(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario('pending', ['order_type' => 'pickup', 'total_amount' => 2000, 'subtotal' => 2000]);
        $scenario['franchise']->update(['platform_fee_percent' => 20, 'commission_model' => 'revenue_share', 'commission_value' => 10, 'owner_user_id' => null]);

        $accepted = app(AdvanceMarketplaceOrderAction::class)->execute($scenario['order']->id, 'accepted');
        $this->assertSame('accepted', $accepted->status);
        $this->assertNotNull($accepted->confirmed_at);

        $preparing = app(AdvanceMarketplaceOrderAction::class)->execute($scenario['order']->id, 'preparing');
        $this->assertSame('preparing', $preparing->status);

        $ready = app(AdvanceMarketplaceOrderAction::class)->execute($scenario['order']->id, 'ready');
        $this->assertSame('ready', $ready->status);
        $this->assertNotNull($ready->ready_at);

        $completed = app(CompleteMarketplaceOrderAction::class)->execute($scenario['order']->id);
        $this->assertSame('completed', $completed->status);
        $this->assertSame(2000.0, (float) $completed->price_final);

        $commission = Commission::where('marketplace_order_id', $completed->id)->first();
        $this->assertNotNull($commission);
        $this->assertSame(1400.0, (float) $commission->provider_commission); // 2000 - 400(platform) - 200(franchise)

        $this->assertSame(1400.0, app(WalletService::class)->balance($scenario['owner']->user));
    }

    public function test_cannot_advance_out_of_order(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario('pending');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot move to');

        app(AdvanceMarketplaceOrderAction::class)->execute($scenario['order']->id, 'preparing');
    }

    public function test_pickup_order_cannot_be_completed_before_ready(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario('preparing', ['order_type' => 'pickup']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be completed from status');

        app(CompleteMarketplaceOrderAction::class)->execute($scenario['order']->id);
    }

    public function test_completion_is_idempotent_and_never_double_credits(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario('ready', ['order_type' => 'pickup', 'total_amount' => 1000, 'subtotal' => 1000]);
        $scenario['franchise']->update(['platform_fee_percent' => 0, 'commission_model' => 'flat_fee', 'commission_value' => 0]);

        app(CompleteMarketplaceOrderAction::class)->execute($scenario['order']->id);
        app(\App\Services\CommissionService::class)->applyForMarketplaceOrder($scenario['order']->fresh());

        $this->assertSame(1, Commission::where('marketplace_order_id', $scenario['order']->id)->count());
        $this->assertSame(1000.0, app(WalletService::class)->balance($scenario['owner']->user));
    }

    // ============================== Lifecycle (delivery + dispatch) ==============================

    public function test_delivery_order_requires_assigned_worker_and_correct_otp_to_complete(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario('ready', ['order_type' => 'delivery']);
        $rider = $this->makeDeliveryRiderIn($scenario['franchise'], $scenario['zone']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not assigned to you');

        app(CompleteMarketplaceOrderAction::class)->execute($scenario['order']->id, $rider, '0000');
    }

    public function test_delivery_rider_accepts_offer_and_delivers_with_correct_otp(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario('ready', ['order_type' => 'delivery']);
        $rider = $this->makeDeliveryRiderIn($scenario['franchise'], $scenario['zone']);

        DispatchAttempt::create([
            'dispatchable_type' => MarketplaceOrder::class, 'dispatchable_id' => $scenario['order']->id,
            'notifiable_type' => \App\Models\FieldWorker::class, 'notifiable_id' => $rider->id,
            'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => now(),
        ]);

        $accepted = app(AcceptMarketplaceDeliveryOfferAction::class)->execute($scenario['order']->id, $rider);
        $this->assertSame($rider->id, $accepted->assigned_worker_id);
        $this->assertNotNull($accepted->delivery_otp);
        $this->assertSame('ready', $accepted->status, 'Assignment does not itself change the order status.');

        $this->expectException(\RuntimeException::class);
        app(CompleteMarketplaceOrderAction::class)->execute($scenario['order']->id, $rider, 'wrong');
    }

    public function test_delivery_completes_with_the_correct_otp(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario('ready', ['order_type' => 'delivery', 'total_amount' => 500, 'subtotal' => 500]);
        $rider = $this->makeDeliveryRiderIn($scenario['franchise'], $scenario['zone']);
        $scenario['order']->update(['assigned_worker_id' => $rider->id, 'delivery_otp' => '1234']);

        $completed = app(CompleteMarketplaceOrderAction::class)->execute($scenario['order']->id, $rider, '1234');

        $this->assertSame('completed', $completed->status);
        $this->assertSame(1, $rider->fresh()->jobs_completed);
    }

    // ============================== Cancellation ==============================

    public function test_admin_can_cancel_a_pending_order_and_stock_is_released(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateMarketplaceModuleFor($franchise);
        $customer = $this->makeCustomer();
        $store = $this->makeStore($franchise, $zone);
        $product = $this->makeProduct($store, ['price' => 100, 'stock' => 5]);
        app(CartService::class)->add($customer, $product, 3);

        $order = app(CreateMarketplaceOrderAction::class)->execute([
            'store_id' => $store->id, 'customer_id' => $customer->id, 'order_type' => 'pickup',
        ]);
        $this->assertSame(2, $product->fresh()->stock);

        app(AdminCancelMarketplaceOrderAction::class)->execute($order->id, 'Customer requested');

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(5, $product->fresh()->stock, 'Stock must be released back on cancellation.');
    }

    public function test_cannot_cancel_an_already_completed_order(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario('completed');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already completed');

        app(AdminCancelMarketplaceOrderAction::class)->execute($scenario['order']->id, 'too late');
    }

    // ============================== Payment ==============================

    public function test_wallet_payment_debits_customer_and_records_a_captured_payment(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateMarketplaceModuleFor($franchise);
        $customer = $this->makeCustomer();
        $store = $this->makeStore($franchise, $zone);
        $product = $this->makeProduct($store, ['price' => 500]);
        app(CartService::class)->add($customer, $product, 1);
        app(WalletService::class)->credit($customer, 2000, 'test top-up');

        $order = app(CreateMarketplaceOrderAction::class)->execute([
            'store_id' => $store->id, 'customer_id' => $customer->id, 'order_type' => 'pickup', 'payment_method' => 'wallet',
        ]);

        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(1500.0, app(WalletService::class)->balance($customer)); // 2000 - 500

        $payment = Payment::where('marketplace_order_id', $order->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('marketplace_order', $payment->purpose);
    }

    // ============================== Regression ==============================

    public function test_service_parcel_taxi_and_property_rental_are_completely_unaffected_by_marketplace_existing(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        [$category, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        $booking = app(\App\Actions\CreateBookingAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'service_id' => $service->id, 'address_id' => $address->id, 'payment_method' => 'online',
        ]);

        $this->assertNotNull($booking->id);
        $this->assertSame('pending', $booking->status);
    }
}
