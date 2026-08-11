<?php

namespace App\Services\Plans;

use Illuminate\Support\Collection;

/**
 * Given N applicable entitlements of the SAME entitlement_type — e.g. two
 * active plans both offering a percentage_discount — returns exactly one
 * deterministic winner. "Never allow ambiguous pricing" (approved plan
 * amendment 4): whatever the inputs, resolveSingleType() always returns the
 * same result for the same inputs, never an arbitrary/unordered pick.
 *
 * Different entitlement TYPES from different plans are never ambiguous in
 * the first place — they simply both apply (amendment 4's "stack" case) —
 * so there is nothing for this resolver to do there; callers only invoke it
 * per entitlement_type group.
 *
 * @phpstan-type Candidate array{subscription: \App\Models\Subscription, plan: \App\Models\Plan, entitlement: \App\Models\PlanEntitlement, benefit_value: float}
 */
class PlanStackingResolver
{
    /** Reuses Setting::SCOPE_ORDER's specificity ordering (most specific first) — narrower down to the plan scope_type vocabulary. */
    private const SPECIFICITY_ORDER = ['zone' => 0, 'franchise' => 1, 'city' => 2, 'country' => 3, 'global' => 4];

    /**
     * @param  Collection<int, array>  $candidates  All same entitlement_type.
     * @return array|null  The winning candidate, or null if $candidates is empty.
     */
    public function resolveSingleType(Collection $candidates): ?array
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        return match ($this->dominantStrategy($candidates)) {
            'priority_order' => $candidates->sortByDesc(fn ($c) => $c['plan']->stacking_priority)->first(),
            'highest_benefit_wins' => $candidates->sortByDesc(fn ($c) => $c['benefit_value'])->first(),
            default => $this->mostSpecific($candidates), // 'exclusive' | 'most_specific_wins' | 'stack' (same-type stack still needs one winner)
        };
    }

    /**
     * Groups a mixed collection by entitlement_type — different types simply
     * combine (amendment 4's "stack"), each resolved independently by the
     * caller; this just isolates the grouping so callers don't repeat it.
     */
    public function groupByType(Collection $allCandidates): Collection
    {
        return $allCandidates->groupBy(fn ($c) => $c['entitlement']->entitlement_type);
    }

    private function dominantStrategy(Collection $candidates): string
    {
        $strategies = $candidates->pluck('plan.stacking_strategy')->unique();

        if ($strategies->contains('priority_order')) {
            return 'priority_order';
        }
        if ($strategies->contains('highest_benefit_wins')) {
            return 'highest_benefit_wins';
        }

        return 'most_specific_wins';
    }

    private function mostSpecific(Collection $candidates): array
    {
        return $candidates
            ->sortBy(fn ($c) => self::SPECIFICITY_ORDER[$c['plan']->scope_type] ?? 99)
            ->first();
    }
}
