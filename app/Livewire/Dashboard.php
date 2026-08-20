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
use App\Services\AuthorizationService;
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
        // own screen requires.
        $links = [
            'bookings' => auth()->user()->hasPermissionAnywhere('bookings.view') ? route('admin.bookings.index') : null,
            'providers' => auth()->user()->hasPermissionAnywhere('providers.view') ? route('admin.providers.index') : null,
            'franchises' => auth()->user()->hasPermissionAnywhere('franchises.manage') ? route('admin.franchises.index') : null,
        ];

        return view('livewire.dashboard', compact('stats', 'funnel', 'recentBookings', 'currencySymbol', 'otherVerticals', 'links'))
            ->layout('layouts.admin', ['title' => 'Dashboard']);
    }
}
