<?php

namespace App\Services\Operations;

use App\Models\User;

/**
 * Admin Polish + AI session, Part 2 — the single place that composes every
 * anomaly detector (StuckBookingService, already existed; ProviderAnomalyService
 * and ZoneCoverageService, new this session) into one fact list. Dashboard's
 * "Daily Insights" panel reads ONLY from here — it does not call the three
 * detectors itself and does not run any query of its own for this purpose,
 * so there is exactly one place that decides what counts as "needs
 * attention today" (the mission's own "reuse existing services, don't build
 * a parallel data layer" instruction, applied to the three detectors
 * themselves).
 *
 * facts() returns plain, already-true statements built from real query
 * results — no LLM involved in deciding WHAT the facts are, ever (mission
 * Part 2 item 3: "the underlying facts must come from real queries, never
 * from the LLM inferring them"). App\Services\Ai\NarrativeAiAdapter (if
 * bound to a real driver) is only ever handed this same fact list to
 * rephrase into prose — see DailyInsightsService.
 */
class OperationalInsightsService
{
    public function __construct(
        private StuckBookingService $stuckBookings,
        private ProviderAnomalyService $providerAnomalies,
        private ZoneCoverageService $zoneCoverage,
    ) {
    }

    /**
     * @return array{stuck_bookings: \Illuminate\Support\Collection, provider_anomalies: \Illuminate\Support\Collection, zone_coverage: \Illuminate\Support\Collection}
     */
    public function collect(User $user): array
    {
        return [
            'stuck_bookings' => $this->stuckBookings->detect($user),
            'provider_anomalies' => $this->providerAnomalies->detect($user),
            'zone_coverage' => $this->zoneCoverage->detect($user),
        ];
    }

    /**
     * Flattens collect()'s three collections into short, human-readable
     * fact strings — the exact sentences DailyInsightsService either shows
     * verbatim (AI disabled/unavailable) or hands to the narrative adapter
     * as its ONLY source of truth (AI enabled). Every sentence here is
     * derived directly from a detector's own output; nothing is phrased by
     * an LLM at this layer.
     *
     * @return array<int, string>
     */
    public function facts(User $user): array
    {
        $data = $this->collect($user);
        $facts = [];

        foreach ($data['stuck_bookings']->take(5) as $row) {
            $facts[] = sprintf(
                'Booking %s has been in "%s" for %d minutes (threshold %d).',
                $row['booking']->code, str_replace('_', ' ', $row['status']), $row['minutes_stuck'], $row['threshold_minutes']
            );
        }

        foreach ($data['provider_anomalies']->take(5) as $row) {
            $facts[] = sprintf(
                'Provider %s is %s at %s%% today, versus their own %s%% trailing average.',
                $row['provider']->user->name ?? ('#'.$row['provider']->id), $row['label'], $row['today_rate'], $row['baseline_rate']
            );
        }

        foreach ($data['zone_coverage']->take(5) as $row) {
            $facts[] = sprintf(
                'Zone %s has only %d online provider(s) available right now (minimum expected: %d).',
                $row['zone']->display_name, $row['online_providers'], $row['threshold']
            );
        }

        return $facts;
    }
}
