<?php

namespace Tests\Feature\Api;

use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Mission Phase 16 (API/security/E2E hardening sweep) — LoyaltyController
 * had real service-level coverage (LoyaltyServiceTest) but zero HTTP-level
 * coverage: nothing previously proved GET /api/loyalty and POST
 * /api/loyalty/redeem are wired to the real service, scoped to the caller,
 * and surface the service's validation errors as 422s rather than a leaked
 * 500 or a silently wrong balance.
 */
class LoyaltyApiTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/loyalty')->assertUnauthorized();
        $this->postJson('/api/loyalty/redeem', ['points' => 100])->assertUnauthorized();
    }

    public function test_returns_the_callers_own_points_balance(): void
    {
        $customer = $this->makeCustomer();
        app(LoyaltyService::class)->earn($customer, 150, 'signup_bonus');

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/loyalty')
            ->assertOk()
            ->assertJson(['points_balance' => 150]);
    }

    public function test_redeeming_enough_points_credits_the_wallet_via_http(): void
    {
        $customer = $this->makeCustomer();
        app(LoyaltyService::class)->earn($customer, 200, 'promo');

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/loyalty/redeem', ['points' => 100]);

        $response->assertOk()
            ->assertJsonPath('points_redeemed', 100)
            ->assertJsonPath('new_balance', 100);
    }

    public function test_redeeming_more_than_the_balance_returns_422_not_a_leaked_500(): void
    {
        $customer = $this->makeCustomer();
        app(LoyaltyService::class)->earn($customer, 50, 'promo');

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/loyalty/redeem', ['points' => 1000])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Insufficient points balance: has 50, requested 1000.']);
    }

    public function test_redeeming_someone_elses_balance_is_impossible_because_the_caller_is_never_taken_from_the_request(): void
    {
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();
        app(LoyaltyService::class)->earn($customerB, 500, 'promo'); // B has plenty; A has none

        // A tries to redeem — nothing in the request lets them spend B's
        // points, since the controller only ever reads $request->user().
        $this->actingAs($customerA, 'sanctum')
            ->postJson('/api/loyalty/redeem', ['points' => 100])
            ->assertStatus(422);

        $this->assertSame(500, app(LoyaltyService::class)->balance($customerB), "B's balance must be untouched by A's request.");
    }
}
