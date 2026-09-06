<?php

namespace Tests\Feature\Plans;

use App\Actions\RedeemEntitlementAction;
use App\Models\EntitlementBalance;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageLedger;
use App\Services\Plans\SubscriptionService;
use Database\Seeders\PrimeSilverPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * PrimeSilverPlanSeeder — the stored configuration of the real membership
 * card, and its re-run safety guard.
 */
class PrimeSilverPlanSeederTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function seedPrimeSilver(): Plan
    {
        $this->seed(PrimeSilverPlanSeeder::class);

        return Plan::where('slug', '1callfix-prime-silver')->with('entitlements')->firstOrFail();
    }

    public function test_it_configures_the_plan_exactly_as_the_card_specifies(): void
    {
        $plan = $this->seedPrimeSilver();

        $this->assertSame('1CallFix Prime Silver — Home Protection Plan', $plan->name);
        $this->assertSame('1999.00', (string) $plan->price);
        $this->assertSame('custom', $plan->billing_cycle);
        $this->assertSame(334, $plan->custom_cycle_days);
        $this->assertSame('customer', $plan->eligible_actor_type);
        $this->assertSame('customer_membership', $plan->plan_family);
        $this->assertTrue($plan->metadata['address_locked']);
        $this->assertFalse($plan->metadata['carry_forward']);
        $this->assertTrue($plan->metadata['spare_parts_chargeable']);
        $this->assertStringContainsString('registered address only', $plan->description);
        $this->assertStringContainsString('separately chargeable', $plan->description);

        $byLabel = $plan->entitlements->keyBy('label');
        $this->assertSame(2, $byLabel['Premium AC Jet Pump Service']->quantity);
        $this->assertSame(1, $byLabel['Appliance General Service']->quantity);
        $this->assertSame(1, $byLabel['Home Service Credit']->quantity);
        $this->assertSame(
            ['electrical', 'plumbing', 'carpenter'],
            $byLabel['Home Service Credit']->redeem_categories
        );
        $this->assertSame('fee_waiver', $byLabel['Free Service Visit (waives visit/inspection fee only)']->entitlement_type);
        $this->assertSame(5, $byLabel['Free Service Visit (waives visit/inspection fee only)']->quantity);

        foreach ($plan->entitlements as $e) {
            $this->assertSame('none', $e->rollover_policy, "{$e->label} must not carry over.");
        }
    }

    public function test_re_running_is_idempotent_when_there_are_no_subscriptions(): void
    {
        $this->seedPrimeSilver();
        $this->seedPrimeSilver();

        $this->assertSame(1, Plan::where('slug', '1callfix-prime-silver')->count());
        $this->assertSame(5, Plan::where('slug', '1callfix-prime-silver')->firstOrFail()->entitlements()->count());
    }

    public function test_it_refuses_to_reconfigure_a_plan_that_already_has_subscribers(): void
    {
        $plan = $this->seedPrimeSilver();

        $customer = $this->makeCustomer();
        $result = app(SubscriptionService::class)->initiateSubscribe($customer, 'customer', $plan);
        $this->assertNotNull(Subscription::find($result['subscription_id']));

        $plan->update(['price' => 1.00]);
        $this->seedPrimeSilver();

        $this->assertSame('1.00', (string) $plan->fresh()->price, 'Seeder must not touch a plan with live subscriptions.');
    }

    public function test_the_seeded_plan_supports_a_real_subscribe_and_ac_redemption(): void
    {
        $plan = $this->seedPrimeSilver();
        $customer = $this->makeCustomer();

        // Priced plan (₹1,999) — initiateSubscribe leaves it pending_payment;
        // activate() is what the captured-payment webhook calls.
        $result = app(SubscriptionService::class)->initiateSubscribe($customer, 'customer', $plan);
        $sub = Subscription::find($result['subscription_id']);
        app(SubscriptionService::class)->activate($sub);
        $sub->refresh();
        $this->assertSame('active', $sub->status);

        $ac = $plan->entitlements->firstWhere('label', 'Premium AC Jet Pump Service');
        $balance = EntitlementBalance::where('subscription_id', $sub->id)
            ->where('plan_entitlement_id', $ac->id)->where('status', 'current')->firstOrFail();

        $this->assertSame(2, $balance->remainingQuantity());

        $row = app(RedeemEntitlementAction::class)->execute($sub, $ac, null, 1, null, $customer->id);

        $this->assertSame(1, $balance->fresh()->remainingQuantity());
        $this->assertSame('consume', $row->event_type);
        $this->assertSame(-1, $row->quantity_delta);
        $this->assertSame(1, UsageLedger::where('subscription_id', $sub->id)->count());
    }
}
