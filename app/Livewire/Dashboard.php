<?php

namespace App\Livewire;

use App\Models\Booking;
use App\Models\Provider;
use App\Models\Franchise;
use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        $today = now()->startOfDay();

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

        $recentBookings = Booking::with(['customer', 'service', 'provider'])
            ->latest()
            ->take(10)
            ->get();

        return view('livewire.dashboard', compact('stats', 'recentBookings'))
            ->layout('layouts.admin', ['title' => 'Dashboard']);
    }
}
