<?php

namespace App\Livewire\Zones;

use App\Models\Zone;
use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        $zones = Zone::with('franchise')
            ->withCount('providers', 'bookings')
            ->orderBy('name')
            ->get();

        return view('livewire.zones.index', compact('zones'))
            ->layout('layouts.admin', ['title' => 'Zones']);
    }
}
