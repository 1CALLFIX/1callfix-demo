<?php

namespace App\Services\Reporting;

use App\Models\Booking;
use App\Models\Provider;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\Operations\OperationalInsightsService;

/**
 * Sidebar Reorganization + Daily Digest session, Part 2 — the single place
 * that builds one recipient's digest payload (KPIs + the same anomaly/
 * insight items the Dashboard's "Daily Insights" panel shows). Reuses,
 * rather than reimplements:
 *
 *  - AuthorizationService::scopeQuery() with the EXACT same {level}_id
 *    column-hint shape Dashboard.php's scopedBookings()/scopedProviders()
 *    already use — a Super Admin (global grant) gets a platform-wide
 *    digest, a Franchise-scoped admin gets only their own franchise's
 *    figures, with no second scoping mechanism invented for this report.
 *  - OperationalInsightsService::collect() for the anomaly/insight
 *    detectors (StuckBookingService, ProviderAnomalyService,
 *    ZoneCoverageService) — this does NOT run its own anomaly queries.
 *
 * Gating mirrors Dashboard.php exactly: KPIs require dashboard.view
 * (DailyDigestDispatchService only resolves recipients who hold it in the
 * first place); insights are only included for a recipient who ALSO holds
 * operations.view, same as the Dashboard panel — a recipient without it
 * gets a real KPI-only digest, not an empty/broken insights section.
 */
class DailyDigestService
{
    /** @var array<string, string> */
    private const SCOPE_COLUMNS = [
        'zone_id' => 'zone_id',
        'franchise_id' => 'franchise_id',
        'city_id' => 'franchise.city_id',
        'country_id' => 'franchise.country_id',
    ];

    public function __construct(
        private AuthorizationService $authz,
        private OperationalInsightsService $insights,
    ) {
    }

    /**
     * @return array{
     *     kpis: array{bookings_today: int, bookings_yesterday: int, completed_today: int, completion_rate: ?float, revenue_today: float, providers_online: int, providers_total: int},
     *     insights: ?array{stuck_bookings: \Illuminate\Support\Collection, provider_anomalies: \Illuminate\Support\Collection, zone_coverage: \Illuminate\Support\Collection},
     * }
     */
    public function forUser(User $user): array
    {
        return [
            'kpis' => $this->kpis($user),
            'insights' => $user->hasPermissionAnywhere('operations.view') ? $this->insights->collect($user) : null,
        ];
    }

    private function scopedBookings(User $user)
    {
        return $this->authz->scopeQuery(Booking::query(), $user, 'dashboard.view', self::SCOPE_COLUMNS);
    }

    private function scopedProviders(User $user)
    {
        return $this->authz->scopeQuery(Provider::query(), $user, 'dashboard.view', self::SCOPE_COLUMNS);
    }

    /**
     * @return array{bookings_today: int, bookings_yesterday: int, completed_today: int, completion_rate: ?float, revenue_today: float, providers_online: int, providers_total: int}
     */
    private function kpis(User $user): array
    {
        $today = now()->startOfDay();
        $yesterday = now()->subDay()->startOfDay();

        // Each scopedBookings($user) call below builds a brand new query
        // from Booking::query() (see scopeQuery()) — no clone() needed, this
        // is never the same builder instance reused across calls.
        $bookingsToday = $this->scopedBookings($user)->where('created_at', '>=', $today)->count();
        $bookingsYesterday = $this->scopedBookings($user)
            ->whereBetween('created_at', [$yesterday, $today])
            ->count();
        $completedToday = $this->scopedBookings($user)
            ->where('status', 'completed')->where('completed_at', '>=', $today)->count();
        $cancelledToday = $this->scopedBookings($user)
            ->where('status', 'cancelled')->where('created_at', '>=', $today)->count();
        $disputedToday = $this->scopedBookings($user)
            ->where('status', 'disputed')->where('created_at', '>=', $today)->count();
        $revenueToday = $this->scopedBookings($user)
            ->where('status', 'completed')->where('completed_at', '>=', $today)->sum('price_final');

        // Same "of CONCLUDED bookings, not of everything created" definition
        // Dashboard.php's own completion_rate uses — see its comment.
        $concluded = $completedToday + $cancelledToday + $disputedToday;

        return [
            'bookings_today' => $bookingsToday,
            'bookings_yesterday' => $bookingsYesterday,
            'completed_today' => $completedToday,
            'completion_rate' => $concluded > 0 ? round(($completedToday / $concluded) * 100, 1) : null,
            'revenue_today' => (float) $revenueToday,
            'providers_online' => $this->scopedProviders($user)->where('is_online', true)->count(),
            'providers_total' => $this->scopedProviders($user)->count(),
        ];
    }
}
