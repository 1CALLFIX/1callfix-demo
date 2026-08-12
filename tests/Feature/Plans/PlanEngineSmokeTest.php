<?php

namespace Tests\Feature\Plans;

use App\Models\EntitlementBalance;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Services\Plans\RenewalService;
use App\Services\Plans\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Priority 2 required test area J (Plan Engine critical smoke tests).
 * PHASE A IS FROZEN — this test file only exercises the existing services
 * through their real public API, never modifies app/Services/Plans/* or
 * the Phase A migrations. QA-only disposable plans, no commercial defaults
 * touched.
 */
class PlanEngineSmokeTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    private function makeFreePlan(string $billingCycle = 'monthly'): Plan
    {
        return Plan::create([
            'name' => 'QA Free Plan', 'slug' => 'qa-free-plan-'.\Illuminate\Support\Str::random(6),
            'plan_family' => 'customer_membership', 'scope_type' => 'global',
            'eligible_actor_type' => 'customer', 'billing_cycle' => $billingCycle,
            'price' => 0, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);
    }

    private function addQuantityEntitlement(Plan $plan, int $quantity = 10): PlanEntitlement
    {
        return PlanEntitlement::create([
            'plan_id' => $plan->id, 'entitlement_type' => 'quantity',
            'quantity' => $quantity, 'usage_period' => 'monthly',
            'consumption_trigger' => 'booking_created', 'rollover_policy' => 'none',
        ]);
    }

    public function test_subscribing_to_a_free_plan_activates_immediately_and_grants_entitlement_balance(): void
    {
        $customer = $this->makeCustomer();
        $plan = $this->makeFreePlan();
        $this->addQuantityEntitlement($plan, 10);

        $result = app(SubscriptionService::class)->initiateSubscribe($customer, 'customer', $plan);

        $this->assertFalse($result['requires_payment']);
        $subscription = Subscription::find($result['subscription_id']);
        $this->assertSame('active', $subscription->status);

        $balance = EntitlementBalance::where('subscription_id', $subscription->id)->first();
        $this->assertNotNull($balance);
        $this->assertSame(10, $balance->granted_quantity);
        $this->assertSame('current', $balance->status);
    }

    public function test_ineligible_actor_type_cannot_purchase(): void
    {
        $customer = $this->makeCustomer();
        $providerOnlyPlan = Plan::create([
            'name' => 'QA Provider Plan', 'slug' => 'qa-provider-plan-'.\Illuminate\Support\Str::random(6),
            'plan_family' => 'provider_package', 'scope_type' => 'global',
            'eligible_actor_type' => 'provider', 'billing_cycle' => 'monthly',
            'price' => 0, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not eligible');
        app(SubscriptionService::class)->initiateSubscribe($customer, 'customer', $providerOnlyPlan);
    }

    public function test_inactive_plan_cannot_be_purchased(): void
    {
        $customer = $this->makeCustomer();
        $plan = $this->makeFreePlan();
        $plan->update(['is_active' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not currently available');
        app(SubscriptionService::class)->initiateSubscribe($customer, 'customer', $plan);
    }

    public function test_cancel_marks_auto_renew_false_but_stays_active_until_period_end(): void
    {
        $customer = $this->makeCustomer();
        $plan = $this->makeFreePlan();
        $result = app(SubscriptionService::class)->initiateSubscribe($customer, 'customer', $plan);
        $subscription = Subscription::find($result['subscription_id']);

        $cancelled = app(SubscriptionService::class)->cancel($subscription, 'QA test cancellation');

        $this->assertFalse((bool) $cancelled->auto_renew);
        $this->assertSame('active', $cancelled->status, 'Cancel is a marker, not an immediate terminal jump — stays usable through current_period_end.');
        $this->assertNotNull($cancelled->cancelled_at);
    }

    public function test_pause_then_resume_round_trips_through_active(): void
    {
        $customer = $this->makeCustomer();
        $plan = $this->makeFreePlan();
        $result = app(SubscriptionService::class)->initiateSubscribe($customer, 'customer', $plan);
        $subscription = Subscription::find($result['subscription_id']);

        $paused = app(SubscriptionService::class)->pause($subscription);
        $this->assertSame('paused', $paused->status);

        $resumed = app(SubscriptionService::class)->resume($paused);
        $this->assertSame('active', $resumed->status);
    }

    /**
     * Regression for 4f92fdf: "RenewalService granted the OLD plan's
     * entitlements after an upgrade/downgrade was applied at renewal."
     * processOne() must apply the pending plan change BEFORE re-reading
     * the subscription's plan for the new period's entitlement balances —
     * this proves the new period's balance actually reflects the NEW
     * plan's entitlement, not the plan that was active when the period
     * started.
     */
    public function test_a_scheduled_upgrade_grants_the_new_plans_entitlements_at_renewal_not_the_old_ones(): void
    {
        $customer = $this->makeCustomer();
        $oldPlan = $this->makeFreePlan('daily');
        $this->addQuantityEntitlement($oldPlan, 5);
        $newPlan = $this->makeFreePlan('daily');
        $this->addQuantityEntitlement($newPlan, 50); // clearly distinct value from the old plan's 5

        $result = app(SubscriptionService::class)->initiateSubscribe($customer, 'customer', $oldPlan);
        $subscription = Subscription::find($result['subscription_id']);
        $this->assertSame(5, EntitlementBalance::where('subscription_id', $subscription->id)->first()->granted_quantity);

        app(SubscriptionService::class)->scheduleUpgrade($subscription, $newPlan);
        $this->assertSame($oldPlan->id, $subscription->fresh()->plan_id, 'The old plan must still be active until renewal actually applies the change.');

        // Force the period to be due for renewal (billing_cycle=daily keeps
        // the period short, but we don't want this test to depend on real
        // wall-clock time passing).
        $subscription->update(['current_period_end' => now()->subMinute()]);

        $outcomes = app(RenewalService::class)->processDueSubscriptions();

        $this->assertSame(1, $outcomes['renewed'] ?? 0);
        $subscription->refresh();
        $this->assertSame($newPlan->id, $subscription->plan_id, 'The pending upgrade must be applied.');

        $currentBalance = EntitlementBalance::where('subscription_id', $subscription->id)->where('status', 'current')->first();
        $this->assertSame(50, $currentBalance->granted_quantity, 'The NEW period must be granted from the NEW plan\'s entitlement, not the old one.');

        $closedBalance = EntitlementBalance::where('subscription_id', $subscription->id)->where('status', 'closed')->first();
        $this->assertNotNull($closedBalance, 'The old period\'s balance must be closed out, never deleted (amendment 8).');
        $this->assertSame(5, $closedBalance->granted_quantity, 'The closed balance must still show what the OLD plan actually granted.');
    }
}
