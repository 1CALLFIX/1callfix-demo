<?php

namespace Tests\Feature\Api;

use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\MarketplaceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * Phase 24 (Marketplace Foundation). Real HTTP-level coverage: public
 * browse (no auth required), authenticated cart + checkout, IDOR-safe
 * "my orders"/detail, module-gate enforcement at the API layer, worker
 * delivery job flow.
 */
class MarketplaceApiTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;

    public function test_browsing_stores_requires_no_authentication(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->makeStore($franchise, $zone);

        $this->getJson('/api/stores')->assertOk()->assertJsonCount(1, 'stores');
    }

    public function test_browsing_products_for_a_store_requires_no_authentication(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $store = $this->makeStore($franchise, $zone);
        $this->makeProduct($store);
        $this->makeProduct($store, ['is_approved' => false]); // pending approval -- must not appear publicly

        $this->getJson("/api/stores/{$store->id}/products")
            ->assertOk()
            ->assertJsonCount(1, 'products');
    }

    public function test_inactive_stores_do_not_appear_in_browse_or_detail(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $store = $this->makeStore($franchise, $zone, null, ['is_active' => false]);

        $this->getJson('/api/stores')->assertJsonCount(0, 'stores');
        $this->getJson("/api/stores/{$store->id}")->assertStatus(404);
    }

    public function test_cart_endpoints_require_authentication(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $store = $this->makeStore($franchise, $zone);

        $this->getJson("/api/cart?store_id={$store->id}")->assertStatus(401);
    }

    public function test_a_customer_can_add_to_cart_and_view_it(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $store = $this->makeStore($franchise, $zone);
        $product = $this->makeProduct($store, ['price' => 100]);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 2])
            ->assertStatus(201);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/cart?store_id={$store->id}")
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('subtotal', 200);
    }

    public function test_cannot_add_an_add_on_from_a_different_store_to_the_cart(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $storeA = $this->makeStore($franchise, $zone);
        $storeB = $this->makeStore($franchise, $zone);
        $product = $this->makeProduct($storeA);
        $foreignAddOn = $this->makeAddOn($storeB, ['price' => 1]); // cheaper add-on from a different store

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 1, 'add_on_ids' => [$foreignAddOn->id]])
            ->assertStatus(422);
    }

    public function test_a_customer_cannot_update_another_customers_cart_item_direct_id_manipulation(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $owner = $this->makeCustomer();
        $store = $this->makeStore($franchise, $zone);
        $product = $this->makeProduct($store);
        $item = app(\App\Services\CartService::class)->add($owner, $product, 1);

        $otherCustomer = $this->makeCustomer();

        $this->actingAs($otherCustomer, 'sanctum')
            ->patchJson("/api/cart/{$item->id}", ['quantity' => 5])
            ->assertStatus(404);
    }

    public function test_checkout_is_blocked_while_the_module_is_disabled(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $store = $this->makeStore($franchise, $zone);
        $product = $this->makeProduct($store);
        app(\App\Services\CartService::class)->add($customer, $product, 1);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/marketplace-orders', ['store_id' => $store->id, 'order_type' => 'pickup'])
            ->assertStatus(422);
    }

    public function test_checkout_succeeds_once_enabled(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateMarketplaceModuleFor($franchise);
        $customer = $this->makeCustomer();
        $store = $this->makeStore($franchise, $zone);
        $product = $this->makeProduct($store, ['price' => 100]);
        app(\App\Services\CartService::class)->add($customer, $product, 1);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/marketplace-orders', ['store_id' => $store->id, 'order_type' => 'pickup'])
            ->assertStatus(201)
            ->assertJsonPath('order.status', 'pending');
    }

    public function test_a_customer_cannot_view_another_customers_order_direct_id_manipulation(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario('pending');
        $otherCustomer = $this->makeCustomer();

        $this->actingAs($otherCustomer, 'sanctum')
            ->getJson("/api/marketplace-orders/{$scenario['order']->id}")
            ->assertStatus(404);
    }

    public function test_mine_only_returns_the_callers_own_orders(): void
    {
        $mine = $this->makeMarketplaceOrderScenario('pending');
        $other = $this->makeMarketplaceOrderScenario('pending');

        $this->actingAs($mine['customer'], 'sanctum')
            ->getJson('/api/marketplace-orders/mine')
            ->assertOk()
            ->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.id', $mine['order']->id);
    }

    // ============================== Worker delivery API ==============================

    public function test_only_a_field_worker_account_can_access_delivery_job_endpoints(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario('ready', ['order_type' => 'delivery']);

        $this->actingAs($scenario['customer'], 'sanctum')
            ->getJson('/api/worker/marketplace-orders')
            ->assertStatus(403);
    }

    public function test_a_rider_can_accept_an_offer_and_deliver_with_the_correct_otp(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario('ready', ['order_type' => 'delivery', 'total_amount' => 300, 'subtotal' => 300]);
        $rider = $this->makeDeliveryRiderIn($scenario['franchise'], $scenario['zone']);

        DispatchAttempt::create([
            'dispatchable_type' => MarketplaceOrder::class, 'dispatchable_id' => $scenario['order']->id,
            'notifiable_type' => FieldWorker::class, 'notifiable_id' => $rider->id,
            'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => now(),
        ]);

        $this->actingAs($rider->user, 'sanctum')
            ->postJson("/api/worker/marketplace-orders/{$scenario['order']->id}/accept")
            ->assertOk();

        $otp = $scenario['order']->fresh()->delivery_otp;

        $this->actingAs($rider->user, 'sanctum')
            ->postJson("/api/worker/marketplace-orders/{$scenario['order']->id}/deliver", ['otp' => $otp])
            ->assertOk()
            ->assertJsonPath('marketplace_order.status', 'completed');
    }

    public function test_a_rider_cannot_deliver_an_order_not_assigned_to_them(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario('ready', ['order_type' => 'delivery']);
        $otherRider = $this->makeDeliveryRiderIn($scenario['franchise'], $scenario['zone']);

        $this->actingAs($otherRider->user, 'sanctum')
            ->postJson("/api/worker/marketplace-orders/{$scenario['order']->id}/deliver", ['otp' => '0000'])
            ->assertStatus(403);
    }
}
