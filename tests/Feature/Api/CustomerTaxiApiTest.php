<?php

namespace Tests\Feature\Api;

use App\Models\Setting;
use App\Models\TaxiRide;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\TaxiRideFixtureHelpers;
use Tests\TestCase;

/**
 * P1 Customer Taxi API — quote/create/history/detail/cancellation, all
 * through the existing CreateTaxiRideAction/AdminCancelTaxiRideAction/
 * TaxiDispatchJob.
 */
class CustomerTaxiApiTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use TaxiRideFixtureHelpers;

    // ============================== quote ==============================

    public function test_quote_requires_authentication(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        [$pickup] = $this->makeTaxiAddresses($franchise, $zone, $customer);

        $this->postJson('/api/taxi-rides/quote', ['pickup_address_id' => $pickup->id])->assertStatus(401);
    }

    public function test_quote_works_with_pickup_only_dropoff_is_optional(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        [$pickup] = $this->makeTaxiAddresses($franchise, $zone, $customer);
        Setting::set('taxi.base_fare', '60', 'franchise', $franchise->id);

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/taxi-rides/quote', ['pickup_address_id' => $pickup->id])
            ->assertOk();

        $this->assertEquals(60, $response->json('data.price_quoted'));
        $this->assertNull($response->json('data.dropoff_address'));
        $this->assertSame(0, TaxiRide::count(), 'A quote must never create a ride.');
    }

    public function test_quote_reports_module_active_flag(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        [$pickup] = $this->makeTaxiAddresses($franchise, $zone, $customer);

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/taxi-rides/quote', ['pickup_address_id' => $pickup->id])
            ->assertOk();
        $this->assertFalse($response->json('data.module_active'));

        $this->activateTaxiFor($franchise);

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/taxi-rides/quote', ['pickup_address_id' => $pickup->id])
            ->assertOk();
        $this->assertTrue($response->json('data.module_active'));
    }

    // ============================== create ==============================

    public function test_module_disabled_blocks_ride_creation(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeTaxiAddresses($franchise, $zone, $customer);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/taxi-rides', ['pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id])
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertSame(0, TaxiRide::count());
    }

    public function test_customer_can_request_a_ride_through_the_real_action_once_enabled(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateTaxiFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeTaxiAddresses($franchise, $zone, $customer);

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/taxi-rides', ['pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id, 'payment_method' => 'cash'])
            ->assertStatus(201)
            ->assertJson(['success' => true]);

        $ride = TaxiRide::first();
        $this->assertSame($customer->id, $ride->customer_id);
        $this->assertSame($franchise->id, $ride->franchise_id);
        $response->assertJsonPath('data.id', $ride->id);
    }

    public function test_ride_can_be_requested_with_pickup_only(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateTaxiFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup] = $this->makeTaxiAddresses($franchise, $zone, $customer);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/taxi-rides', ['pickup_address_id' => $pickup->id])
            ->assertStatus(201);

        $this->assertNull(TaxiRide::first()->dropoff_address_id);
    }

    public function test_client_supplied_price_and_driver_id_are_ignored(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateTaxiFor($franchise);
        $customer = $this->makeCustomer();
        $driver = $this->makeTaxiDriverIn($franchise, $zone);
        [$pickup, $dropoff] = $this->makeTaxiAddresses($franchise, $zone, $customer);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/taxi-rides', [
                'pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id,
                'price_quoted' => 1, 'driver_id' => $driver->id, 'assigned_worker_id' => $driver->id,
            ])
            ->assertStatus(201);

        $ride = TaxiRide::first();
        $this->assertNotEquals(1, $ride->price_quoted);
        $this->assertNull($ride->assigned_worker_id, 'A customer must never be able to directly assign a driver.');
    }

    public function test_a_customer_cannot_request_a_ride_using_another_customers_pickup_address(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateTaxiFor($franchise);
        $customer = $this->makeCustomer();
        $other = $this->makeCustomer();
        [$othersPickup] = $this->makeTaxiAddresses($franchise, $zone, $other);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/taxi-rides', ['pickup_address_id' => $othersPickup->id])
            ->assertStatus(404);

        $this->assertSame(0, TaxiRide::count());
    }

    public function test_create_validates_required_pickup(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/taxi-rides', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pickup_address_id']);
    }

    public function test_wallet_payment_debits_the_wallet_through_the_real_action(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateTaxiFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeTaxiAddresses($franchise, $zone, $customer);
        Wallet::create(['user_id' => $customer->id, 'balance' => 500]);
        Setting::set('taxi.base_fare', '40', 'franchise', $franchise->id);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/taxi-rides', ['pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id, 'payment_method' => 'wallet'])
            ->assertStatus(201);

        $this->assertSame('paid', TaxiRide::first()->payment_status);
        $this->assertEquals(460, $customer->wallet->fresh()->balance);
    }

    // ============================== mine / show ==============================

    public function test_mine_returns_only_the_callers_own_rides_paginated(): void
    {
        $mine = $this->makeTaxiRideScenario();
        $other = $this->makeTaxiRideScenario();

        $response = $this->actingAs($mine['customer'], 'sanctum')
            ->getJson('/api/taxi-rides/mine')
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($mine['ride']->id, $response->json('data.0.id'));
        $this->assertArrayHasKey('pagination', $response->json('meta'));
    }

    public function test_show_returns_real_fsm_status_values_and_driver_when_assigned(): void
    {
        $scenario = $this->makeAssignedTaxiRideScenario();

        $this->actingAs($scenario['customer'], 'sanctum')
            ->getJson("/api/taxi-rides/{$scenario['ride']->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.driver.name', $scenario['driver']->user->name);
    }

    public function test_a_customer_cannot_view_another_customers_ride_idor(): void
    {
        $scenario = $this->makeTaxiRideScenario();
        $other = $this->makeCustomer();

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/taxi-rides/{$scenario['ride']->id}")
            ->assertStatus(404);
    }

    // ============================== cancel ==============================

    public function test_customer_can_cancel_their_own_requested_ride(): void
    {
        $scenario = $this->makeTaxiRideScenario('requested');

        $this->actingAs($scenario['customer'], 'sanctum')
            ->postJson("/api/taxi-rides/{$scenario['ride']->id}/cancel", ['reason' => 'Changed my mind'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_a_customer_cannot_cancel_another_customers_ride(): void
    {
        $scenario = $this->makeTaxiRideScenario('requested');
        $other = $this->makeCustomer();

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/taxi-rides/{$scenario['ride']->id}/cancel", ['reason' => 'Not mine'])
            ->assertStatus(404);
    }

    public function test_an_already_completed_ride_cannot_be_cancelled(): void
    {
        $scenario = $this->makeTaxiRideScenario('trip_completed');

        $this->actingAs($scenario['customer'], 'sanctum')
            ->postJson("/api/taxi-rides/{$scenario['ride']->id}/cancel", ['reason' => 'Too late'])
            ->assertStatus(409);
    }
}
