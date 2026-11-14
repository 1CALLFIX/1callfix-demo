<?php

namespace App\Livewire\Venue;

use App\Livewire\Venue\Concerns\InteractsWithHalls;
use App\Models\Hall;
use App\Services\ModuleActivationService;
use App\Support\Modules;
use Livewire\Component;

/**
 * Hall portal home (Function halls R1): each hall with where it stands (draft / waiting for review / approved / needs
 * changes) and a short checklist of what the team needs before approval.
 */
class Dashboard extends Component
{
    use InteractsWithHalls;

    /** @return list<array{label: string, done: bool}> */
    public static function checklist(Hall $hall): array
    {
        return [
            ['label' => 'Kind of hall, capacity and a few lines about it', 'done' => filled($hall->hall_type) && $hall->seating_capacity > 0 && filled($hall->about)],
            ['label' => 'Cover photo and at least 3 more photos', 'done' => $hall->cover_image !== null && count((array) $hall->photos) >= 3],
            ['label' => 'At least one slot with its time and price', 'done' => $hall->slots->where('is_active', true)->isNotEmpty()],
        ];
    }

    public function render()
    {
        $halls = $this->halls();
        $first = $halls->first();

        return view('livewire.venue.dashboard', [
            'halls' => $halls,
            'partner' => $this->partner(),
            'moduleLive' => $first ? \App\Support\RentalKinds::active(\App\Support\RentalKinds::HALLS, $first->scope()) : false,
        ])->layout('components.layouts.venue', ['title' => 'Home']);
    }
}
