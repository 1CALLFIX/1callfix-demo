<?php

namespace Tests\Feature\Plans;

use App\Actions\RedeemEntitlementAction;
use App\Models\EntitlementBalance;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Models\UsageLedger;
use App\Services\Plans\SubscriptionService;
use App\Services\Plans\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * RedeemEntitlementAction — deliberate redemption of one named entitlement
 * (Prime Silver's "use my AC visit" / "use my Home Service Credit for
 * Plumbing"). Separate from the automatic booking-time pricing path, which
 * cannot target a specific quantity entitlement or record a category
 * choice. Everything real: a Plan is created, subscribed through the real
 * SubscriptionService, and assertions read the persisted
 * entitlement_balances / usage_ledger rows.
 */
class RedeemEntitlementActionTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    /** @return array{0: Subscription, 1: array<string, PlanEntitlement>} */
    private function subscribedPlan(array $entitlements): array
    {
        $plan = Plan::create([
            'name' => 'QA Redeem Plan',
            'slug' => 'qa-redeem-'.Str::random(8),
            'plan_family' => 'customer_membership',
            'scope_type' => 'global',
            'eligible_actor_type' => 'customer',
            'billing_cycle' => 'annual',
            'price' => 0,
            'stacking_strategy' => 'exclusive',
            'is_active' => true,
        ]);

        $made = [];
        foreach ($entitlements as $key => $attrs) {
            $made[$key] = PlanEntitlement::create(array_merge([
                'plan_id' => $plan->id,
                'entitlement_type' => 'quantity',
                'module' => 'service',
                'usage_period' => 'monthly',
                'consumption_trigger' => 'service_completed',
                'rollover_policy' => 'none',
            ], $attrs));
        }

        $customer = $this->makeCustomer();
        $result = app(SubscriptionService::class)->initiateSubscribe($customer, 'customer', $plan);
        $subscription = Subscription::find($result['subscription_id']);

        return [$subscription, $made];
    }

    private function balanceFor(Subscription $sub, PlanEntitlement $ent): EntitlementBalance
    {
        return EntitlementBalance::where('subscription_id', $sub->id)
            ->where('plan_entitlement_id', $ent->id)
            ->where('status', 'current')
            ->firstOrFail();
    }

    public function test_redeeming_one_unit_decrements_the_balance_and_writes_one_consume_row(): void
    {
        [$sub, $ents] = $this->subscribedPlan([
            'ac' => ['label' => 'Premium AC Jet Pump Service', 'quantity' => 2],
        ]);

        $before = $this->balanceFor($sub, $ents['ac'])->remainingQuantity();
        $this->assertSame(2, $before);

        $row = app(RedeemEntitlementAction::class)->execute($sub, $ents['ac']);

        $this->assertSame('consume', $row->event_type);
        $this->assertSame(-1, $row->quantity_delta);

        $after = $this->balanceFor($sub, $ents['ac'])->fresh()->remainingQuantity();
        $this->assertSame(1, $after, 'remainingQuantity must drop by exactly one.');

        $this->assertDatabaseHas('usage_ledger', [
            'id' => $row->id,
            'subscription_id' => $sub->id,
            'plan_entitlement_id' => $ents['ac']->id,
            'event_type' => 'consume',
            'quantity_delta' => -1,
            'redeemed_category' => null,
        ]);
    }

    public function test_second_redemption_exhausts_a_two_unit_entitlement_and_a_third_is_refused(): void
    {
        [$sub, $ents] = $this->subscribedPlan([
            'ac' => ['label' => 'Premium AC Jet Pump Service', 'quantity' => 2],
        ]);

        $action = app(RedeemEntitlementAction::class);
        $action->execute($sub, $ents['ac']);
        $action->execute($sub, $ents['ac']);

        $this->assertSame(0, $this->balanceFor($sub, $ents['ac'])->fresh()->remainingQuantity());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not enough left');
        $action->execute($sub, $ents['ac']);
    }

    public function test_no_ledger_row_is_written_when_redemption_is_refused_for_exhaustion(): void
    {
        [$sub, $ents] = $this->subscribedPlan([
            'appliance' => ['label' => 'Appliance General Service', 'quantity' => 1],
        ]);

        app(RedeemEntitlementAction::class)->execute($sub, $ents['appliance']);
        $ledgerCount = UsageLedger::where('subscription_id', $sub->id)->count();

        try {
            app(RedeemEntitlementAction::class)->execute($sub, $ents['appliance']);
            $this->fail('Expected RuntimeException on the exhausted entitlement.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame($ledgerCount, UsageLedger::where('subscription_id', $sub->id)->count(), 'A refused redemption must write nothing.');
    }

    public function test_category_agnostic_entitlement_records_the_chosen_category_on_the_ledger_row(): void
    {
        [$sub, $ents] = $this->subscribedPlan([
            'home' => [
                'label' => 'Home Service Credit',
                'quantity' => 1,
                'redeem_categories' => ['electrical', 'plumbing', 'carpenter'],
            ],
        ]);

        $row = app(RedeemEntitlementAction::class)->execute($sub, $ents['home'], 'plumbing');

        $this->assertSame('plumbing', $row->redeemed_category);
        $this->assertDatabaseHas('usage_ledger', ['id' => $row->id, 'redeemed_category' => 'plumbing']);
        $this->assertSame(0, $this->balanceFor($sub, $ents['home'])->fresh()->remainingQuantity());
    }

    public function test_category_agnostic_entitlement_requires_a_category(): void
    {
        [$sub, $ents] = $this->subscribedPlan([
            'home' => ['label' => 'Home Service Credit', 'quantity' => 1, 'redeem_categories' => ['electrical', 'plumbing', 'carpenter']],
        ]);

        try {
            app(RedeemEntitlementAction::class)->execute($sub, $ents['home']);
            $this->fail('Expected a missing-category RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('requires a category', $e->getMessage());
        }

        $this->assertSame(1, $this->balanceFor($sub, $ents['home'])->fresh()->remainingQuantity(), 'Balance untouched on a refused redemption.');
    }

    public function test_a_category_outside_the_allowed_set_is_refused(): void
    {
        [$sub, $ents] = $this->subscribedPlan([
            'home' => ['label' => 'Home Service Credit', 'quantity' => 1, 'redeem_categories' => ['electrical', 'plumbing', 'carpenter']],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a valid category');
        app(RedeemEntitlementAction::class)->execute($sub, $ents['home'], 'painting');
    }

    public function test_passing_a_category_to_an_entitlement_that_takes_none_is_refused(): void
    {
        [$sub, $ents] = $this->subscribedPlan([
            'ac' => ['label' => 'Premium AC Jet Pump Service', 'quantity' => 2],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not take a category');
        app(RedeemEntitlementAction::class)->execute($sub, $ents['ac'], 'plumbing');
    }

    public function test_an_entitlement_from_another_plan_cannot_be_redeemed_against_this_subscription(): void
    {
        [$sub] = $this->subscribedPlan(['ac' => ['quantity' => 2]]);
        [, $otherEnts] = $this->subscribedPlan(['ac' => ['quantity' => 2]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not belong to this subscription');
        app(RedeemEntitlementAction::class)->execute($sub, $otherEnts['ac']);
    }

    public function test_a_paused_subscription_refuses_redemption(): void
    {
        [$sub, $ents] = $this->subscribedPlan(['ac' => ['quantity' => 2]]);
        app(SubscriptionService::class)->pause($sub);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not active');
        app(RedeemEntitlementAction::class)->execute($sub->fresh(), $ents['ac']);
    }

    public function test_redemption_stamps_the_acting_user_and_booking_reference(): void
    {
        [$sub, $ents] = $this->subscribedPlan(['ac' => ['label' => 'Premium AC Jet Pump Service', 'quantity' => 2]]);
        $scenario = $this->makeBookingScenario('completed');
        $admin = $this->makeCustomer(); // any real user id

        $row = app(RedeemEntitlementAction::class)->execute(
            $sub, $ents['ac'], null, 1, $scenario['booking'], $admin->id
        );

        $this->assertSame($admin->id, $row->created_by);
        $this->assertSame($scenario['booking']->id, $row->booking_id);
        $this->assertStringContainsString($scenario['booking']->code, $row->reason);
        $this->assertStringContainsString('Premium AC Jet Pump Service', $row->reason);
    }

    public function test_a_redeemed_unit_can_be_reversed_through_the_existing_ledger_mechanism(): void
    {
        [$sub, $ents] = $this->subscribedPlan(['ac' => ['quantity' => 2]]);

        $consume = app(RedeemEntitlementAction::class)->execute($sub, $ents['ac']);
        $this->assertSame(1, $this->balanceFor($sub, $ents['ac'])->fresh()->remainingQuantity());

        app(UsageService::class)->reverse($consume, 'Booking cancelled — visit returned');

        $this->assertSame(2, $this->balanceFor($sub, $ents['ac'])->fresh()->remainingQuantity(), 'Reversal restores the redeemed unit.');
    }
}
