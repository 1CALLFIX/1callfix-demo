<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Mission Phase 16 (API/security/E2E hardening sweep) — GET
 * /api/providers/nearby (ProviderDiscoveryController) reuses
 * DispatchService's exact eligibility/ranking machinery but had zero
 * automated coverage of its own, at any layer — this is the first test
 * this endpoint has ever had.
 */
class ProviderDiscoveryApiTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/providers/nearby?service_id=1&zone_id=1&lat=1&lng=1')->assertUnauthorized();
    }

    public function test_returns_an_eligible_nearby_provider(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        [$category, $service] = $this->makeCategoryAndService();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['skills' => [$category->id], 'current_lat' => 1.001, 'current_lng' => 1.001]);
        $customer = $this->makeCustomer();

        $response = $this->actingAs($customer, 'sanctum')->getJson(
            "/api/providers/nearby?service_id={$service->id}&zone_id={$zone->id}&lat=1&lng=1"
        );

        $response->assertOk();
        $ids = collect($response->json('providers'))->pluck('provider_id');
        $this->assertTrue($ids->contains($provider->id));
    }

    public function test_offline_provider_is_excluded(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        [$category, $service] = $this->makeCategoryAndService();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['skills' => [$category->id], 'current_lat' => 1.001, 'current_lng' => 1.001, 'is_online' => false]);
        $customer = $this->makeCustomer();

        $response = $this->actingAs($customer, 'sanctum')->getJson(
            "/api/providers/nearby?service_id={$service->id}&zone_id={$zone->id}&lat=1&lng=1"
        );

        $response->assertOk();
        $this->assertCount(0, $response->json('providers'), 'An offline provider must never be offered as "nearby".');
    }

    public function test_a_provider_without_the_matching_skill_is_excluded(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        [$category, $service] = $this->makeCategoryAndService();
        $provider = $this->makeProviderIn($franchise, $zone);
        // Skilled for a different category entirely.
        $provider->update(['skills' => [$category->id + 999], 'current_lat' => 1.001, 'current_lng' => 1.001]);
        $customer = $this->makeCustomer();

        $response = $this->actingAs($customer, 'sanctum')->getJson(
            "/api/providers/nearby?service_id={$service->id}&zone_id={$zone->id}&lat=1&lng=1"
        );

        $response->assertOk();
        $this->assertCount(0, $response->json('providers'));
    }

    public function test_invalid_query_params_return_422_not_a_leaked_500(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/providers/nearby?service_id=999999&zone_id=999999&lat=999&lng=999')
            ->assertStatus(422);
    }
}
