<?php

namespace Tests\Feature\Api;

use App\Models\BusinessAccount;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Mission Phase 16 (API/security/E2E hardening sweep) — PlanController had
 * real service-level coverage (PlanEngineSmokeTest, exercising
 * SubscriptionService directly per the Phase A "frozen" mission rule) but
 * zero HTTP-level coverage of the actual public routes. This file only
 * drives the existing API layer through real requests — it does not modify
 * app/Services/Plans/* or any Phase A migration.
 */
class PlanApiTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    private function makeFreePlan(string $actorType = 'customer'): Plan
    {
        return Plan::create([
            'name' => 'QA Free Plan', 'slug' => 'qa-free-plan-'.Str::random(6),
            'plan_family' => 'customer_membership', 'scope_type' => 'global',
            'eligible_actor_type' => $actorType, 'billing_cycle' => 'monthly',
            'price' => 0, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/plans?acting_as=customer')->assertUnauthorized();
        $this->postJson('/api/plans/1/subscribe', ['acting_as' => 'customer'])->assertUnauthorized();
    }

    public function test_lists_only_active_plans_eligible_for_the_requested_actor_type(): void
    {
        $customer = $this->makeCustomer();
        $customerPlan = $this->makeFreePlan('customer');
        $providerPlan = $this->makeFreePlan('provider');
        $inactivePlan = $this->makeFreePlan('customer');
        $inactivePlan->update(['is_active' => false]);

        $response = $this->actingAs($customer, 'sanctum')->getJson('/api/plans?acting_as=customer');

        $response->assertOk();
        $ids = collect($response->json('plans'))->pluck('id');
        $this->assertTrue($ids->contains($customerPlan->id));
        $this->assertFalse($ids->contains($providerPlan->id), 'A provider-only plan must not be listed for acting_as=customer.');
        $this->assertFalse($ids->contains($inactivePlan->id), 'An inactive plan must never be listed.');
    }

    public function test_invalid_acting_as_returns_422(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/plans?acting_as=alien')
            ->assertStatus(422);
    }

    public function test_subscribing_to_a_free_plan_activates_immediately_via_http(): void
    {
        $customer = $this->makeCustomer();
        $plan = $this->makeFreePlan();

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson("/api/plans/{$plan->id}/subscribe", ['acting_as' => 'customer']);

        $response->assertOk()->assertJson(['requires_payment' => false]);
        $subscription = Subscription::find($response->json('subscription_id'));
        $this->assertSame('active', $subscription->status);
        $this->assertSame($customer->id, $subscription->subscribable_id);
    }

    public function test_cannot_subscribe_a_business_account_the_caller_does_not_own(): void
    {
        $customer = $this->makeCustomer();
        $otherOwner = $this->makeCustomer();
        $foreignAccount = BusinessAccount::create([
            'owner_user_id' => $otherOwner->id, 'name' => 'Not Yours Inc', 'business_type' => 'retail', 'status' => 'active',
        ]);
        $plan = $this->makeFreePlan('business_account');

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/plans/{$plan->id}/subscribe", [
                'acting_as' => 'business_account',
                'business_account_id' => $foreignAccount->id,
            ])
            ->assertStatus(404);

        $this->assertSame(0, Subscription::count(), 'No subscription must be created against a business account the caller does not own.');
    }

    public function test_ineligible_actor_type_returns_422_not_a_leaked_500(): void
    {
        $customer = $this->makeCustomer();
        $providerOnlyPlan = $this->makeFreePlan('provider');

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/plans/{$providerOnlyPlan->id}/subscribe", ['acting_as' => 'customer'])
            ->assertStatus(422);
    }
}
