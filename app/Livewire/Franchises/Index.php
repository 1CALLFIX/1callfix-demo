<?php

namespace App\Livewire\Franchises;

use App\Models\Franchise;
use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        $franchises = Franchise::with('modules')->withCount('zones', 'providers', 'bookings')->latest()->get();

        return view('livewire.franchises.index', compact('franchises'))
            ->layout('layouts.admin', ['title' => 'Franchises']);
    }
}
