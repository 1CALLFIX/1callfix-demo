<?php

namespace Tests\Feature\Api;

use App\Models\BusinessAccount;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Plans\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Mission Phase 16 (API/security/E2E hardening sweep) — SubscriptionController's
 * own docblock states "every action re-checks ownership server-side... never
 * trusts that a subscription id in the URL belongs to the caller" but,
 * before this file, nothing at the HTTP layer ever actually proved that —
 * only PlanEngineSmokeTest exercised SubscriptionService directly. This
 * file drives the real routes end-to-end; it does not modify
 * app/Services/Plans/* or any Phase A migration (Phase A stays frozen).
 */
class SubscriptionApiTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    private function makeFreePlan(): Plan
    {
        return Plan::create([
            'name' => 'QA Free Plan', 'slug' => 'qa-free-plan-'.Str::random(6),
            'plan_family' => 'customer_membership', 'scope_type' => 'global',
            'eligible_actor_type' => 'customer', 'billing_cycle' => 'monthly',
            'price' => 0, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);
    }

    private function subscribeCustomer($customer, ?Plan $plan = null): Subscription
    {
        $plan ??= $this->makeFreePlan();
        $result = app(SubscriptionService::class)->initiateSubscribe($customer, 'customer', $plan);

        return Subscription::find($result['subscription_id']);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/subscriptions/mine')->assertUnauthorized();
        $this->getJson('/api/subscriptions/1/entitlements')->assertUnauthorized();
        $this->getJson('/api/subscriptions/1/usage')->assertUnauthorized();
        $this->postJson('/api/subscriptions/1/cancel')->assertUnauthorized();
    }

    public function test_mine_lists_only_the_callers_own_subscriptions(): void
    {
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();
        $subA = $this->subscribeCustomer($customerA);
        $this->subscribeCustomer($customerB);

        $response = $this->actingAs($customerA, 'sanctum')->getJson('/api/subscriptions/mine');

        $response->assertOk();
        $ids = collect($response->json('subscriptions'))->pluck('id');
        $this->assertSame([$subA->id], $ids->all());
    }

    public function test_mine_includes_subscriptions_held_via_an_owned_business_account(): void
    {
        $owner = $this->makeCustomer();
        $account = BusinessAccount::create([
            'owner_user_id' => $owner->id, 'name' => 'Owned Biz', 'business_type' => 'retail', 'status' => 'active',
        ]);
        $plan = Plan::create([
            'name' => 'QA Biz Plan', 'slug' => 'qa-biz-plan-'.Str::random(6),
            'plan_family' => 'business_package', 'scope_type' => 'global',
            'eligible_actor_type' => 'business_account', 'billing_cycle' => 'monthly',
            'price' => 0, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);
        $result = app(SubscriptionService::class)->initiateSubscribe($account, 'business_account', $plan);

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/subscriptions/mine');

        $ids = collect($response->json('subscriptions'))->pluck('id');
        $this->assertTrue($ids->contains($result['subscription_id']));
    }

    public function test_a_stranger_cannot_read_someone_elses_subscription_entitlements(): void
    {
        $owner = $this->makeCustomer();
        $stranger = $this->makeCustomer();
        $subscription = $this->subscribeCustomer($owner);

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/subscriptions/{$subscription->id}/entitlements")
            ->assertForbidden()
            ->assertJson(['message' => 'Not your subscription.']);
    }

    public function test_a_stranger_cannot_read_someone_elses_usage_ledger(): void
    {
        $owner = $this->makeCustomer();
        $stranger = $this->makeCustomer();
        $subscription = $this->subscribeCustomer($owner);

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/subscriptions/{$subscription->id}/usage")
            ->assertForbidden();
    }

    public function test_a_stranger_cannot_cancel_someone_elses_subscription(): void
    {
        $owner = $this->makeCustomer();
        $stranger = $this->makeCustomer();
        $subscription = $this->subscribeCustomer($owner);

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/subscriptions/{$subscription->id}/cancel")
            ->assertForbidden();

        $this->assertSame('active', $subscription->fresh()->status, "A stranger's cancel attempt must not change the real owner's subscription.");
    }

    public function test_owner_can_cancel_their_own_subscription_via_http(): void
    {
        $owner = $this->makeCustomer();
        $subscription = $this->subscribeCustomer($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/subscriptions/{$subscription->id}/cancel", ['reason' => 'no longer needed'])
            ->assertOk();

        $this->assertNotNull($subscription->fresh()->cancelled_at);
    }

    public function test_owner_can_read_their_own_entitlements_and_usage_via_http(): void
    {
        $owner = $this->makeCustomer();
        $subscription = $this->subscribeCustomer($owner);

        $this->actingAs($owner, 'sanctum')->getJson("/api/subscriptions/{$subscription->id}/entitlements")->assertOk();
        $this->actingAs($owner, 'sanctum')->getJson("/api/subscriptions/{$subscription->id}/usage")->assertOk();
    }
}
