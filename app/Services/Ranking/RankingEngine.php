<?php

namespace App\Services\Ranking;

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

        return $candidates
            ->map(function ($c) use ($weights, $totalWeight, $radiusKm, $maxPriority, $maxOrders) {
                $normalized = [
                    'priority' => ($c['provider']->priority ?? 0) / $maxPriority,
                    'rating' => min(1, max(0, ($c['provider']->rating_avg ?? 0) / 5)),
                    'distance' => $radiusKm > 0 ? max(0, 1 - (($c['distance_km'] ?? 0) / $radiusKm)) : 0,
                    'orders' => ($c['provider']->jobs_completed ?? 0) / $maxOrders,
                    'subscription' => $this->hasActiveSubscription($c['provider']) ? 1 : 0,
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
            'subscription' => $this->hasActiveSubscription($candidate['provider']) ? 1.0 : 0.0,
            default => 0.0,
        };
    }

    private function hasActiveSubscription($provider): bool
    {
        if (! $provider->relationLoaded('subscriptions')) {
            $provider->load('subscriptions');
        }

        return $provider->subscriptions->contains(
            fn ($s) => $s->status === 'successful' && (! $s->expires_at || $s->expires_at->isFuture())
        );
    }
}
