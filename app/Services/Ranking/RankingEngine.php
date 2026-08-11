<?php

namespace App\Services\Ranking;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Ranks an already-eligibility-filtered candidate collection (see
 * DispatchService — geospatial/zone/skill/availability filtering happens
 * in SQL/PHP before this ever runs, on a small candidate set; this never
 * ranks providers outside the applicable radius). Two modes:
 *
 *  - sequential: deterministic primary/secondary/tertiary sort — what
 *    "Priority DESC, then Rating DESC, then Distance ASC" means literally.
 *  - weighted: each criterion normalized to 0..1, combined by its
 *    configured weight into one blended score, highest first. Deliberately
 *    NOT a machine-learning model — a plain, auditable weighted sum.
 *
 * The 'subscription' criterion reads the Plan Engine's real
 * subscriptions/plans tables (approved plan §20 — the old
 * provider_subscriptions-backed boolean is superseded), NOT a flat
 * has-a-subscription boolean: it's the NUMERIC value of the provider's
 * active Provider Package plan's 'priority'-type entitlement (its
 * plan_entitlements.quantity, treated as a tier's priority-boost score) —
 * so a higher-tier package genuinely outranks a lower-tier one, and a
 * provider with no package (or an expired one) contributes 0, exactly as
 * before the Plan Engine existed. See resolvePlanPriority().
 *
 * @param  Collection<int, array{provider: \App\Models\Provider, distance_km: float}>  $candidates
 */
class RankingEngine
{
    public function rank(Collection $candidates, array $config, float $radiusKm = 0): Collection
    {
        if ($candidates->isEmpty()) {
            return $candidates;
        }

        return $config['mode'] === 'weighted'
            ? $this->rankWeighted($candidates, $config['weights'], $radiusKm)
            : $this->rankSequential($candidates, $config['sequential']);
    }

    private function rankSequential(Collection $candidates, array $criteria): Collection
    {
        return $candidates->sort(function ($a, $b) use ($criteria) {
            foreach ($criteria as $c) {
                $cmp = $this->rawValue($a, $c['key']) <=> $this->rawValue($b, $c['key']);
                if ($cmp !== 0) {
                    return $c['direction'] === 'desc' ? -$cmp : $cmp;
                }
            }

            return 0;
        })->values();
    }

    private function rankWeighted(Collection $candidates, array $weights, float $radiusKm): Collection
    {
        $totalWeight = array_sum($weights);

        if ($totalWeight <= 0) {
            // No weight assigned to anything -- fall back to the same
            // distance-ascending default sequential mode uses, rather than
            // returning candidates in an arbitrary/unranked order.
            return $this->rankSequential($candidates, [['key' => 'distance', 'direction' => 'asc']]);
        }

        $maxPriority = max(1, $candidates->max(fn ($c) => $c['provider']->priority ?? 0));
        $maxOrders = max(1, $candidates->max(fn ($c) => $c['provider']->jobs_completed ?? 0));
        $maxPlanPriority = max(1.0, $candidates->max(fn ($c) => $this->resolvePlanPriority($c['provider'])));

        return $candidates
            ->map(function ($c) use ($weights, $totalWeight, $radiusKm, $maxPriority, $maxOrders, $maxPlanPriority) {
                $normalized = [
                    'priority' => ($c['provider']->priority ?? 0) / $maxPriority,
                    'rating' => min(1, max(0, ($c['provider']->rating_avg ?? 0) / 5)),
                    'distance' => $radiusKm > 0 ? max(0, 1 - (($c['distance_km'] ?? 0) / $radiusKm)) : 0,
                    'orders' => ($c['provider']->jobs_completed ?? 0) / $maxOrders,
                    'subscription' => $this->resolvePlanPriority($c['provider']) / $maxPlanPriority,
                ];

                $score = 0;
                foreach ($normalized as $key => $value) {
                    $score += $value * $weights[$key];
                }

                $c['ranking_score'] = round($score / $totalWeight, 4);

                return $c;
            })
            ->sortByDesc('ranking_score')
            ->values();
    }

    private function rawValue(array $candidate, string $key): float
    {
        return match ($key) {
            'priority' => (float) ($candidate['provider']->priority ?? 0),
            'rating' => (float) ($candidate['provider']->rating_avg ?? 0),
            'distance' => (float) ($candidate['distance_km'] ?? PHP_FLOAT_MAX),
            'orders' => (float) ($candidate['provider']->jobs_completed ?? 0),
            'subscription' => $this->resolvePlanPriority($candidate['provider']),
            default => 0.0,
        };
    }

    /**
     * Highest 'priority'-type entitlement value across the provider's
     * active/grace_period Provider Package subscriptions — 0 if they hold
     * none, an expired one, or no matching entitlement. A provider with two
     * stacked packages doesn't double-count: this is a max, not a sum,
     * matching PlanStackingResolver's "one deterministic winner" spirit for
     * a single ranking signal.
     */
    private function resolvePlanPriority($provider): float
    {
        if (! $provider->user_id) {
            return 0.0;
        }

        $subscriptions = Subscription::where('subscribable_type', User::class)
            ->where('subscribable_id', $provider->user_id)
            ->whereIn('status', ['active', 'grace_period'])
            ->with(['plan.entitlements' => fn ($q) => $q->where('entitlement_type', 'priority')])
            ->get();

        $best = 0.0;
        foreach ($subscriptions as $subscription) {
            foreach ($subscription->plan->entitlements as $entitlement) {
                if (! $entitlement->isUsable()) {
                    continue;
                }
                $best = max($best, (float) ($entitlement->quantity ?? 0));
            }
        }

        return $best;
    }
}
