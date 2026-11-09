<?php

namespace App\Livewire;

use App\Models\Booking;
use App\Models\MarketplaceOrder;
use App\Models\ParcelOrder;
use App\Models\Provider;
use App\Models\Franchise;
use App\Models\Setting;
use App\Services\Ai\DailyInsightsService;
use App\Services\AuthorizationService;
use App\Services\ProviderCommercialRateResolver;
use App\Services\TimezoneResolver;
use App\Support\AdminNav;
use App\Support\ModuleSales;
use App\Support\Modules;
use App\Support\Turnaround;
use Livewire\Component;

class Dashboard extends Component
{
    public string $period = 'week'; // today | week | month | year

    /**
     * Owner request 2026-11-03: the dashboard is tabbed by module. 'all' is the whole business (every module's
     * orders and sales in one table, with a grand total); each module tab shows that module's own numbers and its
     * quick links (the same links as its side-menu group). A tab the viewer cannot open falls back to 'all'.
     */
    public string $tab = 'all';

    protected $queryString = ['tab' => ['except' => 'all'], 'period' => ['except' => 'week']];

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
        $this->period = in_array($period, ['today', 'week', 'month', 'year'], true) ? $period : 'week';
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
    }

    /** Tab keys this viewer can open, in menu order: 'all' plus every module they hold a permission for. */
    private function tabs(): array
    {
        // Owner 2026-11-09: only modules that are built and switched on somewhere; the rest stay off the dashboard.
        $visible = array_intersect(ModuleSales::visibleKeys(auth()->user()), ModuleSales::liveKeys());
        $tabs = ['all' => ['label' => 'All modules', 'colour' => 'slate', 'prelaunch' => false]];
        foreach (ModuleSales::modules() as $key => $m) {
            if (in_array($key, $visible, true)) {
                $tabs[$key] = ['label' => $m['label'], 'colour' => $m['colour'], 'prelaunch' => $m['prelaunch']];
            }
        }

        return $tabs;
    }

    /** The side-menu group of a module (quick links on its tab), already filtered to what the viewer can open. */
    private function quickLinks(string $tab): array
    {
        [$groups] = AdminNav::forUser(auth()->user());

        foreach ($groups as $group) {
            if (($group['module'] ?? null) === $tab) {
                return $group['items'];
            }
        }

        return [];
    }

    /** Ten newest shop or food orders, on the Marketplace Orders row scope. */
    private function recentMarketplace(bool $food)
    {
        $columns = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];

        return app(AuthorizationService::class)
            ->scopeQuery(MarketplaceOrder::query()->booked(), auth()->user(), 'marketplace_orders.view', $columns)
            ->where('module', $food ? '=' : '!=', Modules::FOOD)
            ->with(['customer', 'store', 'statusHistory' => Turnaround::historyScope()])
            ->latest('id')->take(10)->get();
    }

    /** Ten newest parcel orders, on the Parcel Orders row scope. */
    private function recentParcels()
    {
        $columns = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];

        return app(AuthorizationService::class)
            ->scopeQuery(ParcelOrder::query()->booked(), auth()->user(), 'parcel_orders.view', $columns)
            ->with(['customer', 'assignedWorker.user', 'statusHistory' => Turnaround::historyScope()])
            ->latest('id')->take(10)->get();
    }

    /** Who is working right now (owner 2026-11-09: something alive on the dashboard). Each tile opens its list. */
    private function teamNow(): array
    {
        $user = auth()->user();
        $auth = app(AuthorizationService::class);
        $columns = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
        $tiles = [];

        if ($user->hasPermissionAnywhere('workers.view')) {
            $riders = $auth->scopeQuery(\App\Models\FieldWorker::query(), $user, 'workers.view', $columns)->where('kyc_status', 'approved');
            $tiles[] = ['label' => 'Riders on duty', 'value' => (clone $riders)->where('is_online', true)->count(), 'of' => $riders->count(),
                'icon' => 'map-pin', 'colour' => 'orange', 'url' => route('admin.partners.index', ['tab' => 'people'])];
        }
        if ($user->hasPermissionAnywhere('providers.view')) {
            $pros = $auth->scopeQuery(Provider::query(), $user, 'providers.view', $columns)->where('kyc_status', 'approved');
            $tiles[] = ['label' => 'Partners on duty', 'value' => (clone $pros)->where('is_online', true)->count(), 'of' => $pros->count(),
                'icon' => 'wrench', 'colour' => 'blue', 'url' => route('admin.partners.index', ['tab' => 'businesses'])];
        }
        if ($user->hasPermissionAnywhere('stores.manage')) {
            $stores = $auth->scopeQuery(\App\Models\Store::query(), $user, 'stores.manage', $columns)->where('is_active', true)->where('approval_status', 'approved');
            $tiles[] = ['label' => 'Shops & restaurants open', 'value' => (clone $stores)->where('is_open', true)->count(), 'of' => $stores->count(),
                'icon' => 'shopping-cart', 'colour' => 'violet', 'url' => route('admin.stores.index')];
        }

        return $tiles;
    }

    /** Where each top card goes when tapped. */
    private function kpiLinks(): array
    {
        $payments = auth()->user()->hasPermissionAnywhere('payments.view');

        return [
            'orders' => '#sales-by-module',
            'sales' => '#sales-by-module',
            'online' => $payments ? route('admin.payments.index', ['methodFilter' => 'online']) : '#sales-by-module',
            'cash' => $payments ? route('admin.payments.index', ['methodFilter' => 'cash']) : '#sales-by-module',
        ];
    }

    private function periodStart()
    {
        return match ($this->period) {
            'today' => now(app(TimezoneResolver::class)->platformTimezone())->startOfDay()->utc(),
            'month' => now()->startOfMonth(),
            'year' => now()->startOfYear(),
            default => now()->startOfWeek(),
        };
    }

    private function scopedBookings()
    {
        $columns = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];

        // booked(): unpaid online attempts are not bookings and are not counted (NO PAYMENT, NO BOOKING, owner 2026-11-07).
        return app(AuthorizationService::class)->scopeQuery(Booking::query()->booked(), auth()->user(), 'dashboard.view', $columns);
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
     * Parcel is the live vertical, so it gets its own "Parcel — Today" panel (owner 2026-10-28) instead of one
     * card among the pre-launch verticals. Same permission and row scope as the Parcel screens; "today" is the
     * platform's day, as on the Parcel Dashboard. Null when the viewer cannot open Parcel.
     *
     * @return array{orders: int, live: int, delivered: int, cancelled: int, revenue: float, avg_tat: ?int, route: string, dashboard: string}|null
     */
    private function parcelToday(): ?array
    {
        $user = auth()->user();
        if (! $user->hasPermissionAnywhere('parcel_orders.view')) {
            return null;
        }

        $columns = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
        $todayStart = now(app(TimezoneResolver::class)->platformTimezone())->startOfDay()->utc();
        $today = app(AuthorizationService::class)->scopeQuery(ParcelOrder::query()->booked(), $user, 'parcel_orders.view', $columns)
            ->where('created_at', '>=', $todayStart)
            ->get(['id', 'status', 'created_at', 'delivered_at', 'price_quoted', 'price_final']);

        $delivered = $today->where('status', 'delivered');
        $tats = $delivered->filter(fn ($o) => $o->delivered_at)->map(fn ($o) => Turnaround::seconds($o->created_at, $o->delivered_at));

        return [
            'orders' => $today->count(),
            'live' => $today->whereIn('status', array_merge(['pending', 'searching_worker'], ParcelOrder::ACTIVE_RIDER_STATUSES))->count(),
            'delivered' => $delivered->count(),
            'cancelled' => $today->where('status', 'cancelled')->count(),
            'revenue' => (float) $delivered->sum(fn ($o) => $o->price_final ?? $o->price_quoted),
            'avg_tat' => $tats->isEmpty() ? null : (int) round($tats->avg()),
            'route' => route('admin.parcel-orders.index'),
            'dashboard' => route('admin.parcel-orders.dashboard'),
        ];
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
        $tabs = $this->tabs();
        if (! isset($tabs[$this->tab])) {
            $this->tab = 'all';
        }

        $currencySymbol = Setting::get('locale.currency_symbol', '₹');
        $periodLabel = ['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month', 'year' => 'This Year'][$this->period] ?? 'This Week';
        $moduleSales = array_intersect_key(ModuleSales::summary(auth()->user(), $this->periodStart()), $tabs);
        // Owner 2026-11-09: the dates the numbers cover ("Today · Mon, 9 Nov 2026") and a greeting with today's date.
        $now = now(app(TimezoneResolver::class)->platformTimezone());
        $periodDates = match ($this->period) {
            'today' => $now->format('D, j M Y'),
            'month' => $now->format('F Y'),
            'year' => $now->format('Y'),
            default => $now->copy()->startOfWeek()->format('j M').' – '.$now->format('j M Y'),
        };
        $greeting = ['hour' => (int) $now->format('G'), 'name' => auth()->user()->name, 'date' => $now->format('l, j F Y')];
        $shared = compact('tabs', 'currencySymbol', 'periodLabel', 'moduleSales', 'periodDates', 'greeting');

        if ($this->tab === 'all') {
            // Daily Insights: gated by operations.view (see the services branch below for the reasoning).
            $insights = auth()->user()->hasPermissionAnywhere('operations.view')
                ? app(DailyInsightsService::class)->digest(auth()->user())
                : null;

            return view('livewire.dashboard', $shared + [
                'salesTotal' => ModuleSales::total($moduleSales),
                'insights' => $insights,
                'daily' => ModuleSales::daily(auth()->user(), array_keys($moduleSales), 7, app(TimezoneResolver::class)->platformTimezone()),
                'team' => $this->teamNow(),
                'kpiLinks' => $this->kpiLinks(),
            ])
                ->layout('layouts.admin', ['title' => 'Dashboard']);
        }

        if ($this->tab !== 'service') {
            $recent = match ($this->tab) {
                'parcel' => $this->recentParcels(),
                'shop' => $this->recentMarketplace(false),
                'food' => $this->recentMarketplace(true),
                default => collect(),
            };

            return view('livewire.dashboard', $shared + [
                'row' => $moduleSales[$this->tab],
                'quickLinks' => $this->quickLinks($this->tab),
                'recent' => $recent,
                'parcelToday' => $this->tab === 'parcel' ? $this->parcelToday() : null,
            ])->layout('layouts.admin', ['title' => 'Dashboard']);
        }

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
            ->with(['customer', 'service', 'provider.user', 'statusHistory' => Turnaround::historyScope()])
            ->latest()
            ->take(10)
            ->get();


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
        // convention every module tab follows.
        $insights = auth()->user()->hasPermissionAnywhere('operations.view')
            ? app(DailyInsightsService::class)->digest(auth()->user())
            : null;

        return view('livewire.dashboard', $shared + compact('stats', 'funnel', 'trend', 'recentBookings', 'links', 'insights', 'commissionRatesConfigured') + [
            'row' => $moduleSales['service'], 'quickLinks' => $this->quickLinks('service'),
        ])
            ->layout('layouts.admin', ['title' => 'Dashboard']);
    }
}
