<?php

namespace App\Services\Operations;

use App\Models\Provider;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Services\AuthorizationService;
use Illuminate\Support\Collection;

/**
 * Admin Polish + AI session, Part 2 item 1 — "zones with unusually low
 * provider coverage right now". Read-only, no auto-action, same discipline
 * as StuckBookingService/ProviderAnomalyService.
 *
 * Deliberately a flat "fewer than N online-and-eligible providers right
 * now" check, not a baseline-relative one like ProviderAnomalyService's
 * rejection/cancellation rates. A provider's online/offline state changes
 * continuously minute-to-minute (not an append-only event log like
 * dispatch_attempts/bookings), so a real trailing-baseline for "normal
 * online count" would need its own time-series snapshot table this
 * codebase doesn't have — building that is a materially bigger, separate
 * decision, not something to bolt on silently here. A flat, admin-
 * adjustable minimum is the honest, simple v1: it tells an operator
 * "this zone currently cannot be dispatched to" (or is close to it), which
 * is the actionable fact regardless of whether today's count is "normal"
 * for that zone.
 */
class ZoneCoverageService
{
    private const DEFAULT_MIN_ONLINE_PROVIDERS = 1;

    /** Row-level scoped the same way every other operational panel is — operations.view via AuthorizationService::scopeQuery(). */
    public function detect(User $user): Collection
    {
        $authz = app(AuthorizationService::class);
        $columns = ['zone_id' => 'id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];

        $minOnline = (int) Setting::get('operations.coverage.min_online_providers', (string) self::DEFAULT_MIN_ONLINE_PROVIDERS);

        $zones = $authz->scopeQuery(Zone::query(), $user, 'operations.view', $columns)
            ->where('is_active', true)
            ->whereHas('franchise.modules', fn ($q) => $q->where('service', true))
            ->with('franchise')
            ->get();

        if ($zones->isEmpty()) {
            return collect();
        }

        // One grouped query for every zone's live online-provider count,
        // not one query per zone.
        $onlineCounts = Provider::whereIn('zone_id', $zones->pluck('id'))
            ->where('is_online', true)
            ->where('is_active', true)
            ->where('kyc_status', 'approved')
            ->selectRaw('zone_id, count(*) as total')
            ->groupBy('zone_id')
            ->pluck('total', 'zone_id');

        return $zones
            ->map(fn (Zone $zone) => [
                'zone' => $zone,
                'online_providers' => (int) ($onlineCounts[$zone->id] ?? 0),
                'threshold' => $minOnline,
            ])
            ->filter(fn ($row) => $row['online_providers'] < $row['threshold'])
            ->sortBy('online_providers')
            ->values();
    }
}
