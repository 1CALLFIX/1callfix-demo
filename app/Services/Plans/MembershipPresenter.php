<?php

namespace App\Services\Plans;

use App\Models\EntitlementBalance;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\PlanEntitlementTarget;
use App\Models\UsageLedger;
use App\Services\TimezoneResolver;
use Illuminate\Support\Collection;

/**
 * One place that turns plan / balance / ledger rows into the plain arrays the
 * customer API and the customer web pages both render. Read-only: it computes
 * nothing about money or eligibility, it only names and shapes what the engine
 * already stored, so the web and the mobile API can never disagree about what
 * "2 / 2 remaining" means.
 */
class MembershipPresenter
{
    /**
     * A plan as shown to a customer: price, validity, terms and every benefit.
     *
     * @return array<string, mixed>
     */
    public function plan(Plan $plan): array
    {
        $plan->loadMissing('entitlements.targets');

        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'description' => $plan->description,
            'price' => (float) $plan->price,
            'validity_label' => $plan->validityLabel(),
            'validity_months' => $plan->validity_months,
            'address_locked' => $plan->isAddressLocked(),
            'terms' => array_values($plan->metadata['terms'] ?? []),
            'metadata' => $plan->metadata ?? [],
            'entitlements' => $plan->entitlements->where('is_enabled', true)->map(fn (PlanEntitlement $e) => $this->entitlement($e))->values()->all(),
        ];
    }

    /**
     * One benefit definition.
     *
     * @return array<string, mixed>
     */
    public function entitlement(PlanEntitlement $entitlement): array
    {
        $entitlement->loadMissing('targets');

        return [
            'entitlement_id' => $entitlement->id,
            'name' => $entitlement->displayName(),
            'type' => $entitlement->entitlement_type,
            'effect' => $entitlement->redemption_effect,
            'total_quantity' => $entitlement->quantity,
            'advertised_value' => $entitlement->redemption_effect === PlanEntitlement::EFFECT_VISIT_FEE_WAIVER
                ? $this->liveVisitCharge()
                : ($entitlement->monetary_value !== null ? (float) $entitlement->monetary_value : null),
            'description' => $entitlement->description,
            'choices' => $entitlement->redeem_categories ?: [],
            'includes' => $entitlement->includes ?: [],
            'excludes' => $entitlement->excludes ?: [],
            'eligible' => $entitlement->targets
                ->map(fn (PlanEntitlementTarget $t) => [
                    'type' => $t->target_type,
                    'id' => $t->target_id,
                    'choice' => $t->choice_key,
                    'excluded' => (bool) $t->is_excluded,
                    'name' => trim(explode(':', $t->label(), 2)[1] ?? ''),
                ])->values()->all(),
        ];
    }

    /**
     * A subscriber's balance for one benefit: how much there was, how much is left.
     *
     * @return array<string, mixed>
     */
    public function balance(EntitlementBalance $balance): array
    {
        $entitlement = $balance->planEntitlement;
        $limited = $entitlement->quantity !== null;
        $tracked = ((float) $balance->granted_monetary_value + (float) $balance->rolled_over_monetary_value) > 0;

        return $this->entitlement($entitlement) + [
            'balance_id' => $balance->id,
            'entitlement_type' => $entitlement->entitlement_type,
            'limited' => $limited,
            'total' => $limited ? $balance->granted_quantity + $balance->rolled_over_quantity : null,
            'remaining' => $limited ? max(0, $balance->remainingQuantity()) : null,
            'remaining_quantity' => $balance->remainingQuantity(),
            // A benefit that carries no money value (granted 0) still records the discount it gave on
            // each use; reporting granted - consumed would show a meaningless negative, so report 0.
            'monetary_tracked' => $tracked,
            'remaining_monetary_value' => $tracked ? $balance->remainingMonetaryValue() : 0.0,
            'used' => $balance->consumed_quantity - $balance->reversed_quantity,
            'period_start' => $balance->period_start,
            'period_end' => $balance->period_end,
        ];
    }

    /**
     * One ledger row in words a customer can read.
     *
     * @return array<string, mixed>
     */
    public function usage(UsageLedger $row): array
    {
        return $row->only([
            'id', 'subscription_id', 'plan_entitlement_id', 'booking_id', 'event_type',
            'quantity_delta', 'monetary_delta', 'reason', 'redeemed_category', 'created_at',
        ]) + [
            'entitlement_name' => $row->planEntitlement?->displayName(),
            'booking_code' => $row->booking?->code,
            'at' => app(TimezoneResolver::class)->format($row->created_at, null, 'j M Y, g:i A'),
        ];
    }

    /**
     * Balances for a subscription's CURRENT period, in the plan's own entitlement order.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function currentBalances(\App\Models\Subscription $subscription): Collection
    {
        return $subscription->entitlementBalances()
            ->where('status', 'current')
            ->with('planEntitlement.targets')
            ->orderBy('plan_entitlement_id')
            ->get()
            ->map(fn (EntitlementBalance $b) => $this->balance($b));
    }

    /**
     * Turns a stored includes/excludes value into [label => list] groups.
     * A plain list becomes one unlabelled group; a map keyed by choice
     * (Home Service Credit: electrical / plumbing / carpenter) keeps its keys.
     *
     * @return array<string, array<int, string>>
     */
    public function groups(?array $scope): array
    {
        if (! $scope) {
            return [];
        }

        return array_is_list($scope) ? ['' => $scope] : $scope;
    }

    /** The flat visit charge a free cancellation waives, read live from the cancellation policy setting. */
    private function liveVisitCharge(): ?float
    {
        $type = \App\Models\Setting::get('cancellation.visit_fee_type', 'flat');
        $value = \App\Models\Setting::get('cancellation.visit_fee_value');

        return $type === 'flat' && is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }
}
