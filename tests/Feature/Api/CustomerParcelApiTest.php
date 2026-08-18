<?php

namespace Tests\Feature\Api;

use App\Models\ParcelOrder;
use App\Models\Setting;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * P1 Customer Parcel API — quote/create/history/detail/cancellation, all
 * through the existing CreateParcelOrderAction/AdminCancelParcelOrderAction.
 */
class CustomerParcelApiTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;

    // ============================== quote ==============================

    public function test_quote_requires_authentication(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);

        $this->postJson('/api/parcel-orders/quote', ['pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id])
            ->assertStatus(401);
    }

    public function test_quote_computes_the_real_server_side_price_and_reports_module_active_flag(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);
        Setting::set('parcel.base_fare', '20', 'franchise', $franchise->id);
        Setting::set('parcel.per_kg_rate', '5', 'franchise', $franchise->id);

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/parcel-orders/quote', [
                'pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id, 'package_weight_kg' => 3,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals(35, $response->json('data.price_quoted')); // 20 + 5*3
        $this->assertFalse($response->json('data.module_active'), 'parcel is not activated for this franchise yet.');

        $this->activateParcelFor($franchise);

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/parcel-orders/quote', [
                'pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id, 'package_weight_kg' => 3,
            ])
            ->assertOk();
        $this->assertTrue($response->json('data.module_active'));

        $this->assertSame(0, ParcelOrder::count(), 'A quote must never create an order.');
    }

    public function test_quote_rejects_an_address_not_owned_by_the_customer(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $other = $this->makeCustomer();
        [$pickup] = $this->makeParcelAddresses($franchise, $zone, $customer);
        [, $othersDropoff] = $this->makeParcelAddresses($franchise, $zone, $other);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/parcel-orders/quote', ['pickup_address_id' => $pickup->id, 'dropoff_address_id' => $othersDropoff->id])
            ->assertStatus(404);
    }

    // ============================== create ==============================

    public function test_module_disabled_blocks_creation(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/parcel-orders', ['pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id])
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertSame(0, ParcelOrder::count());
    }

    public function test_customer_can_create_a_parcel_order_through_the_real_action_once_enabled(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/parcel-orders', [
                'pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id,
                'package_size' => 'medium', 'package_weight_kg' => 2, 'payment_method' => 'cash',
            ])
            ->assertStatus(201)
            ->assertJson(['success' => true]);

        $order = ParcelOrder::first();
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame($franchise->id, $order->franchise_id);
        $this->assertSame('medium', $order->package_size);
        $response->assertJsonPath('data.id', $order->id);
    }

    public function test_client_supplied_price_franchise_zone_and_customer_are_ignored(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        [, , $otherFranchise, $otherZone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $otherCustomer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/parcel-orders', [
                'pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id,
                'price_quoted' => 1, 'franchise_id' => $otherFranchise->id, 'zone_id' => $otherZone->id,
                'customer_id' => $otherCustomer->id,
            ])
            ->assertStatus(201);

        $order = ParcelOrder::first();
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame($franchise->id, $order->franchise_id);
        $this->assertNotEquals(1, $order->price_quoted);
    }

    public function test_a_customer_cannot_create_an_order_using_another_customers_pickup_address(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();
        $other = $this->makeCustomer();
        [$othersPickup, $othersDropoff] = $this->makeParcelAddresses($franchise, $zone, $other);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/parcel-orders', ['pickup_address_id' => $othersPickup->id, 'dropoff_address_id' => $othersDropoff->id])
            ->assertStatus(404);

        $this->assertSame(0, ParcelOrder::count());
    }

    public function test_create_validates_required_addresses(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/parcel-orders', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pickup_address_id', 'dropoff_address_id']);
    }

    public function test_wallet_payment_debits_the_wallet_through_the_real_action(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);
        Wallet::create(['user_id' => $customer->id, 'balance' => 1000]);
        Setting::set('parcel.base_fare', '30', 'franchise', $franchise->id);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/parcel-orders', ['pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id, 'payment_method' => 'wallet'])
            ->assertStatus(201);

        $this->assertSame('paid', ParcelOrder::first()->payment_status);
        $this->assertEquals(970, $customer->wallet->fresh()->balance);
    }

    // ============================== mine / show ==============================

    public function test_mine_returns_only_the_callers_own_orders_paginated(): void
    {
        $mine = $this->makeParcelOrderScenario();
        $other = $this->makeParcelOrderScenario();

        $response = $this->actingAs($mine['customer'], 'sanctum')
            ->getJson('/api/parcel-orders/mine')
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($mine['order']->id, $response->json('data.0.id'));
        $this->assertArrayHasKey('pagination', $response->json('meta'));
    }

    public function test_mine_returns_empty_list_for_a_customer_with_no_orders(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/parcel-orders/mine')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_show_returns_the_callers_own_order_with_rider_when_assigned(): void
    {
        $scenario = $this->makeAssignedParcelOrderScenario();

        $this->actingAs($scenario['customer'], 'sanctum')
            ->getJson("/api/parcel-orders/{$scenario['order']->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $scenario['order']->id)
            ->assertJsonPath('data.rider.name', $scenario['rider']->user->name);
    }

    public function test_a_customer_cannot_view_another_customers_parcel_order_idor(): void
    {
        $scenario = $this->makeParcelOrderScenario();
        $other = $this->makeCustomer();

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/parcel-orders/{$scenario['order']->id}")
            ->assertStatus(404);
    }

    // ============================== cancel ==============================

    public function test_customer_can_cancel_their_own_pending_order(): void
    {
        $scenario = $this->makeParcelOrderScenario('pending');

        $this->actingAs($scenario['customer'], 'sanctum')
            ->postJson("/api/parcel-orders/{$scenario['order']->id}/cancel", ['reason' => 'Changed my mind'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_cancel_requires_a_reason(): void
    {
        $scenario = $this->makeParcelOrderScenario('pending');

        $this->actingAs($scenario['customer'], 'sanctum')
            ->postJson("/api/parcel-orders/{$scenario['order']->id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_a_customer_cannot_cancel_another_customers_order(): void
    {
        $scenario = $this->makeParcelOrderScenario('pending');
        $other = $this->makeCustomer();

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/parcel-orders/{$scenario['order']->id}/cancel", ['reason' => 'Not mine'])
            ->assertStatus(404);

        $this->assertSame('pending', $scenario['order']->fresh()->status);
    }

    public function test_an_already_delivered_order_cannot_be_cancelled(): void
    {
        $scenario = $this->makeParcelOrderScenario('delivered');

        $this->actingAs($scenario['customer'], 'sanctum')
            ->postJson("/api/parcel-orders/{$scenario['order']->id}/cancel", ['reason' => 'Too late'])
            ->assertStatus(409);
    }
}
