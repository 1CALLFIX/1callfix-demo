<?php

namespace App\Livewire;

use App\Models\Booking;
use App\Models\Provider;
use App\Models\Franchise;
use App\Models\Setting;
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

        return view('livewire.dashboard', compact('stats', 'funnel', 'recentBookings', 'currencySymbol'))
            ->layout('layouts.admin', ['title' => 'Dashboard']);
    }
}
