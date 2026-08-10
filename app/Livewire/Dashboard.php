<?php

namespace App\Livewire;

use App\Models\Booking;
use App\Models\Provider;
use App\Models\Franchise;
use App\Models\Setting;
use Livewire\Component;

class Dashboard extends Component
{
    public string $period = 'week'; // week | month | year

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

    public function render()
    {
        $today = now()->startOfDay();
        $periodStart = $this->periodStart();

        $stats = [
            'bookings_today' => Booking::where('created_at', '>=', $today)->count(),
            'active_bookings' => Booking::whereIn('status', [
                'searching_provider', 'assigned', 'provider_en_route', 'in_progress', 'on_hold',
            ])->count(),
            'completed_today' => Booking::where('status', 'completed')
                ->where('completed_at', '>=', $today)->count(),
            'revenue_today' => Booking::where('status', 'completed')
                ->where('completed_at', '>=', $today)->sum('price_final'),
            'providers_online' => Provider::where('is_online', true)->count(),
            'providers_total' => Provider::count(),
            'franchises_active' => Franchise::where('status', 'active')->count(),
        ];

        // Operational funnel for the selected period — mirrors the reference
        // admin's "Unassigned / Accepted / Preparing / Delivered / Cancelled"
        // row, mapped onto our own booking status pipeline.
        $funnel = [
            'searching' => Booking::where('status', 'searching_provider')->where('created_at', '>=', $periodStart)->count(),
            'assigned' => Booking::whereIn('status', ['assigned', 'provider_en_route'])->where('created_at', '>=', $periodStart)->count(),
            'in_progress' => Booking::whereIn('status', ['in_progress', 'on_hold'])->where('created_at', '>=', $periodStart)->count(),
            'completed' => Booking::where('status', 'completed')->where('created_at', '>=', $periodStart)->count(),
            'cancelled' => Booking::where('status', 'cancelled')->where('created_at', '>=', $periodStart)->count(),
            'disputed' => Booking::where('status', 'disputed')->where('created_at', '>=', $periodStart)->count(),
        ];

        $recentBookings = Booking::with(['customer', 'service', 'provider'])
            ->latest()
            ->take(10)
            ->get();

        $currencySymbol = Setting::get('locale.currency_symbol', '₹');

        return view('livewire.dashboard', compact('stats', 'funnel', 'recentBookings', 'currencySymbol'))
            ->layout('layouts.admin', ['title' => 'Dashboard']);
    }
}
