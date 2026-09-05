<?php

namespace App\Livewire;

use App\Models\Booking;
use App\Models\HotelReservation;
use App\Models\MarketplaceOrder;
use App\Models\ParcelOrder;
use App\Models\Provider;
use App\Models\PropertyReservation;
use App\Models\RentalReservation;
use App\Models\Franchise;
use App\Models\Setting;
use App\Models\TaxiRide;
use App\Services\Ai\DailyInsightsService;
use App\Services\AuthorizationService;
use App\Services\ProviderCommercialRateResolver;
use Livewire\Component;

class Dashboard extends Component
{
    public string $period = 'week'; // week | month | year

    /**
     * dashboard.view was seeded (2026_08_11_016000) but never checked -- see
     * Commissions\Index's identical fix for the full reasoning. Exposes
     * today's revenue and franchise-wide operational counts.
     *
     * Row-level scoping (was deferred here, now closed): every stat below
     * aggregates a DIFFERENT model (Booking/Provider/Franchise), each scoped
     * independently under the same dashboard.view grant -- a Zone Admin's
     * dashboard now reflects only their own zone's bookings/providers and
     * the one franchise their zone belongs to, not the whole platform.
     */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('dashboard.view'), 403, 'You do not have permission to view the dashboard.');
    }

    public function setPeriod(string $period)
    {
        $this->period = $period;
    }

    private function periodStart()
    {
        return match ($this->period) {
            'month' => now()->startOfMonth(),
            'year' => now()->startOfYear(),
            default => now()->startOfWeek(),
        };
    }

    private function scopedBookings()
    {
        $columns = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];

        return app(AuthorizationService::class)->scopeQuery(Booking::query(), auth()->user(), 'dashboard.view', $columns);
    }

    private function scopedProviders()
    {
        $columns = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];

        return app(AuthorizationService::class)->scopeQuery(Provider::query(), auth()->user(), 'dashboard.view', $columns);
    }

    private function scopedFranchises()
    {
        $columns = ['franchise_id' => 'id', 'city_id' => 'city_id', 'country_id' => 'country_id', 'zone_id' => 'zones.id'];

        return app(AuthorizationService::class)->scopeQuery(Franchise::query(), auth()->user(), 'dashboard.view', $columns);
    }

    /**
     * Admin Command Center mission (Phase 4 audit finding) — this screen was
     * entirely Booking/Service-only; an admin had no way to see today's
     * Parcel/Taxi/Rental/Hotel/Marketplace volume anywhere on the dashboard,
     * even after those verticals shipped real admin screens. Each entry
     * here is gated by the SAME permission its own vertical admin screen's
     * mount() already requires (not dashboard.view) -- a viewer only sees a
     * vertical's card if they can actually open that vertical's screen,
     * same discipline GlobalSearch already established this session. Every
     * module still ships `is_implemented=false` by default, so most of
     * these will genuinely read 0 in production until a real business
     * activation decision is made -- that's accurate, not fabricated.
     *
     * @return array<int, array{label: string, count: int, route: string}>
     */
    private function otherVerticalStats(): array
    {
        $user = auth()->user();
        $authz = app(AuthorizationService::class);
        $columns = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
        $today = now()->startOfDay();

        $verticals = [
            ['permission' => 'parcel_orders.view', 'model' => ParcelOrder::class, 'label' => 'Parcel Orders Today', 'route' => 'admin.parcel-orders.index'],
            ['permission' => 'taxi_rides.view', 'model' => TaxiRide::class, 'label' => 'Taxi Rides Today', 'route' => 'admin.taxi-rides.index'],
            ['permission' => 'property_reservations.view', 'model' => PropertyReservation::class, 'label' => 'Property Reservations Today', 'route' => 'admin.property-reservations.index'],
            ['permission' => 'rental_reservations.view', 'model' => RentalReservation::class, 'label' => 'Rental Reservations Today', 'route' => 'admin.rental-reservations.index'],
            ['permission' => 'hotel_reservations.view', 'model' => HotelReservation::class, 'label' => 'Hotel Reservations Today', 'route' => 'admin.hotel-reservations.index'],
            ['permission' => 'marketplace_orders.view', 'model' => MarketplaceOrder::class, 'label' => 'Marketplace Orders Today', 'route' => 'admin.marketplace-orders.index'],
        ];

        $stats = [];
        foreach ($verticals as $v) {
            if (! $user->hasPermissionAnywhere($v['permission'])) {
                continue;
            }

            $count = $authz->scopeQuery($v['model']::query(), $user, $v['permission'], $columns)
                ->where('created_at', '>=', $today)
                ->count();

            $stats[] = ['label' => $v['label'], 'count' => $count, 'route' => route($v['route'])];
        }

        return $stats;
    }

    /**
     * Admin Polish + AI session, Part 1 item 1 — a real 7-day trend, not
     * just a point-in-time number. Rendered as an inline SVG in the view
     * (server-computed points, no JS charting library / CDN dependency —
     * this environment has no Node/npm toolchain to build one in anyway,
     * see KNOWN_RISKS_AND_DECISIONS.md) — a genuine chart because a single
     * "today" count can't show a trend a 7-day shape can.
     *
     * Two grouped queries total (bookings-by-day, revenue-by-day), not one
     * query per day — the same anti-N+1 discipline items 44-53 already
     * established elsewhere in this admin panel.
     *
     * @return array<int, array{date: string, label: string, bookings: int, revenue: float}>
     */
    private function sevenDayTrend(): array
    {
        $start = now()->copy()->subDays(6)->startOfDay();

        $bookingsByDay = (clone $this->scopedBookings())
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as d, count(*) as total')
            ->groupBy('d')
            ->pluck('total', 'd');

        $revenueByDay = (clone $this->scopedBookings())
            ->where('status', 'completed')
            ->where('completed_at', '>=', $start)
            ->selectRaw('DATE(completed_at) as d, sum(price_final) as total')
            ->groupBy('d')
            ->pluck('total', 'd');

        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = $start->copy()->addDays(6 - $i);
            $key = $day->toDateString();

            $days[] = [
                'date' => $key,
                'label' => $day->format('D'),
                'bookings' => (int) ($bookingsByDay[$key] ?? 0),
                'revenue' => (float) ($revenueByDay[$key] ?? 0),
            ];
        }

        return $days;
    }

    public function render()
    {
        $today = now()->startOfDay();
        $periodStart = $this->periodStart();

        $stats = [
            'bookings_today' => $this->scopedBookings()->where('created_at', '>=', $today)->count(),
            'active_bookings' => $this->scopedBookings()->whereIn('status', [
                'searching_provider', 'assigned', 'provider_en_route', 'in_progress', 'on_hold',
            ])->count(),
            'completed_today' => $this->scopedBookings()->where('status', 'completed')
                ->where('completed_at', '>=', $today)->count(),
            'revenue_today' => $this->scopedBookings()->where('status', 'completed')
                ->where('completed_at', '>=', $today)->sum('price_final'),
            'providers_online' => $this->scopedProviders()->where('is_online', true)->count(),
            'providers_total' => $this->scopedProviders()->count(),
            'franchises_active' => $this->scopedFranchises()->where('status', 'active')->count(),
            // Admin Command Center mission (Phase 4) — the mission's own
            // "Active Operations" priority list names "unassigned bookings"
            // explicitly; the existing funnel only ever showed it filtered
            // to the selected period (week/month/year), never as the
            // current, un-time-boxed backlog an operator actually needs to
            // act on right now.
            'unassigned_bookings' => $this->scopedBookings()->where('status', 'searching_provider')->count(),
        ];

        // Operational funnel for the selected period — mirrors the reference
        // admin's "Unassigned / Accepted / Preparing / Delivered / Cancelled"
        // row, mapped onto our own booking status pipeline.
        $funnel = [
            'searching' => $this->scopedBookings()->where('status', 'searching_provider')->where('created_at', '>=', $periodStart)->count(),
            'assigned' => $this->scopedBookings()->whereIn('status', ['assigned', 'provider_en_route'])->where('created_at', '>=', $periodStart)->count(),
            'in_progress' => $this->scopedBookings()->whereIn('status', ['in_progress', 'on_hold'])->where('created_at', '>=', $periodStart)->count(),
            'completed' => $this->scopedBookings()->where('status', 'completed')->where('created_at', '>=', $periodStart)->count(),
            'cancelled' => $this->scopedBookings()->where('status', 'cancelled')->where('created_at', '>=', $periodStart)->count(),
            'disputed' => $this->scopedBookings()->where('status', 'disputed')->where('created_at', '>=', $periodStart)->count(),
        ];

        // Admin Polish + AI session, Part 1 item 1 — "completion rate" was
        // named explicitly and wasn't on this screen at all. Of bookings
        // that have actually CONCLUDED this period (completed, cancelled,
        // or disputed) — not of everything created, which would understate
        // it by counting bookings still legitimately in progress as if
        // they'd failed.
        $concluded = $funnel['completed'] + $funnel['cancelled'] + $funnel['disputed'];
        $stats['completion_rate'] = $concluded > 0 ? round(($funnel['completed'] / $concluded) * 100, 1) : null;

        $trend = $this->sevenDayTrend();

        $recentBookings = $this->scopedBookings()
            ->with(['customer', 'service', 'provider'])
            ->latest()
            ->take(10)
            ->get();

        $currencySymbol = Setting::get('locale.currency_symbol', '₹');
        $otherVerticals = $this->otherVerticalStats();

        // Drill-down targets (mission's own explicit "allow dashboard cards
        // to drill directly into the underlying records" requirement) --
        // built here, not hardcoded in the view, so a card is only ever
        // linked if the viewer actually holds the permission that route's
        // own screen requires. Each value is a URL to an EXISTING admin
        // screen, pre-filtered via that screen's own query-string bindings
        // (Bookings\Index::$statusFilter, Providers\Index::$onlineOnly,
        // Franchises\Manage::$filterStatus, Commissions\Index::$fromDate/
        // $toDate) — no new pages.
        $user = auth()->user();
        $canBookings = $user->hasPermissionAnywhere('bookings.view');
        $canProviders = $user->hasPermissionAnywhere('providers.view');
        $canFranchises = $user->hasPermissionAnywhere('franchises.manage');
        $canCommissions = $user->hasPermissionAnywhere('commissions.view');
        $todayDate = $today->toDateString();

        $bookingsByStatus = fn (string $status) => $canBookings
            ? route('admin.bookings.index', ['statusFilter' => $status])
            : null;

        $links = [
            'bookings' => $canBookings ? route('admin.bookings.index') : null,
            'bookings_searching' => $bookingsByStatus('searching_provider'),
            'bookings_assigned' => $bookingsByStatus('assigned'),
            'bookings_in_progress' => $bookingsByStatus('in_progress'),
            'bookings_completed' => $bookingsByStatus('completed'),
            'bookings_cancelled' => $bookingsByStatus('cancelled'),
            'bookings_disputed' => $bookingsByStatus('disputed'),
            'providers' => $canProviders ? route('admin.providers.index') : null,
            'providers_online' => $canProviders
                ? route('admin.providers.index', ['statusFilter' => '', 'onlineOnly' => 1])
                : null,
            'franchises' => $canFranchises ? route('admin.franchises.index') : null,
            'franchises_active' => $canFranchises
                ? route('admin.franchises.index', ['filterStatus' => 'active'])
                : null,
            'commissions' => $canCommissions ? route('admin.commissions.index') : null,
            'commissions_today' => $canCommissions
                ? route('admin.commissions.index', ['fromDate' => $todayDate, 'toDate' => $todayDate])
                : null,
        ];

        // Part A finding surfaced on the dashboard: every active franchise in
        // this viewer's scope is on the zero default for BOTH commission
        // levers (franchises.platform_fee_percent / commission_value), so
        // CommissionService splits 100% to the provider and books nothing
        // for platform or franchise. Drives the amber note on the
        // Commissions card — a silent link-through would hide the fact that
        // the numbers on that screen are all 100/0/0 by omission, not by
        // decision.
        //
        // Provider Commercial Rate Resolver phase — platform_fee_percent is
        // no longer a raw column check: NULL now means "unconfigured, falls
        // through to the global Setting default" (30% as of that phase),
        // which is a real, non-zero rate, not the 100/0/0-by-omission bug
        // this banner exists to catch. A plain `> 0` SQL check on the column
        // would now flag every unconfigured-but-fine franchise as broken, so
        // this resolves the EFFECTIVE rate per franchise in PHP instead —
        // small scoped-franchise sets only, same as the query this replaces.
        // commission_value's SQL check is untouched: that axis still has no
        // resolver/global-default of its own in this phase (see
        // ProviderCommercialRateResolver's docblock), so a literal 0 there is
        // still exactly the same real gap this banner always caught.
        $commissionRatesConfigured = $canCommissions && (clone $this->scopedFranchises())
            ->where('status', 'active')
            ->get(['id', 'platform_fee_percent', 'commission_value'])
            ->contains(fn (Franchise $f) => app(ProviderCommercialRateResolver::class)->resolve($f, null) > 0
                || ($f->commission_value ?? 0) > 0);

        // Admin Polish + AI session, Part 2 item 3 — Daily Insights panel.
        // Gated by operations.view, same as StuckBookingService's own
        // scopeQuery() calls inside OperationalInsightsService's three
        // detectors already require — a viewer without it would just see
        // an always-empty panel (scopeQuery() fails closed), so it's hidden
        // entirely instead, same "gate each card by its own permission"
        // convention otherVerticalStats() above already established.
        $insights = auth()->user()->hasPermissionAnywhere('operations.view')
            ? app(DailyInsightsService::class)->digest(auth()->user())
            : null;

        return view('livewire.dashboard', compact('stats', 'funnel', 'trend', 'recentBookings', 'currencySymbol', 'otherVerticals', 'links', 'insights', 'commissionRatesConfigured'))
            ->layout('layouts.admin', ['title' => 'Dashboard']);
    }
}
