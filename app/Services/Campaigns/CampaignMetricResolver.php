<?php

namespace App\Services\Campaigns;

use App\Models\Booking;
use App\Models\FieldWorker;
use App\Models\Franchise;
use App\Models\PerformanceCampaign;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The ONLY place a Performance/Growth Campaign's metric_value is computed.
 * Deliberately a small, fixed, honest set of metrics derived from REAL,
 * already-written columns (Booking.status/completed_at/price_final) — the
 * engine does not accept an admin-authored formula, and never counts a
 * booking that isn't genuinely 'completed' (which already excludes
 * cancelled/refunded bookings, closing the "cancelled/refunded transaction
 * abuse" and "fake performance" prevention requirements at the query level
 * rather than as a separate fraud check bolted on afterward).
 */
class CampaignMetricResolver
{
    public const SUPPORTED = ['bookings_completed_count', 'revenue_generated'];

    /**
     * @param  Model  $actor  a Franchise, Provider, FieldWorker, or User row —
     *         must match $campaign->participantModelClass().
     */
    public function valueFor(PerformanceCampaign $campaign, Model $actor): float
    {
        $query = Booking::where('status', 'completed');

        $query = match ($campaign->audience_type) {
            'franchise' => $query->where('franchise_id', $actor->id),
            'provider' => $query->where('provider_id', $actor->id),
            'field_worker' => $query->where('assigned_worker_id', $actor->id),
            'customer' => $query->where('customer_id', $actor->id),
        };

        if ($campaign->starts_at) {
            $query->where('completed_at', '>=', $campaign->starts_at);
        }
        if ($campaign->ends_at) {
            $query->where('completed_at', '<=', $campaign->ends_at);
        }

        return match ($campaign->metric_key) {
            'bookings_completed_count' => (float) $query->count(),
            'revenue_generated' => (float) $query->sum('price_final'),
            default => throw new \InvalidArgumentException("Unsupported campaign metric [{$campaign->metric_key}]."),
        };
    }

    /**
     * Every real candidate actor for this campaign's audience_type — the
     * full table, unfiltered by scope here (PerformanceCampaignService
     * applies the scope filter via AuthorizationService::scopeCovers() per
     * actor, the same ancestor-inclusive check used everywhere else this
     * session, rather than a second scope implementation).
     */
    public function candidateActors(PerformanceCampaign $campaign): \Illuminate\Support\Collection
    {
        return match ($campaign->audience_type) {
            'franchise' => Franchise::all(),
            'provider' => Provider::all(),
            'field_worker' => FieldWorker::all(),
            'customer' => User::where('role', 'customer')->get(),
        };
    }

    /**
     * The actor's own ancestor-inclusive geography, in the exact shape
     * AuthorizationService::scopeCovers() expects — reuses
     * AuthorizationService::ancestryFor() rather than re-walking the
     * franchise/zone tree a second time.
     */
    public function ancestryFor(\App\Services\AuthorizationService $authz, PerformanceCampaign $campaign, Model $actor): array
    {
        return match ($campaign->audience_type) {
            'franchise' => $authz->ancestryFor('franchise', $actor->id),
            'provider', 'field_worker' => $actor->zone_id
                ? $authz->ancestryFor('zone', $actor->zone_id)
                : $authz->ancestryFor('franchise', $actor->franchise_id),
            'customer' => $actor->zone_id
                ? $authz->ancestryFor('zone', $actor->zone_id)
                : $authz->ancestryFor('franchise', $actor->franchise_id),
        };
    }
}
