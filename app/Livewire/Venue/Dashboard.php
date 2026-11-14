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

    /** @return list<array{label: string, done: bool}> */
    public static function homeChecklist(\App\Models\HouseListing $home): array
    {
        $min = (int) \App\Support\HomeSettings::get('homes.min_photos', $home->scope());

        return [
            ['label' => 'Kind of property, size, furnishing and who you rent to', 'done' => filled($home->home_type) && (filled($home->size) || \App\Support\HomeSettings::isCommercial($home->home_type, $home->scope())) && filled($home->furnishing) && ! empty($home->tenants)],
            ['label' => 'Rent per month and when it is ready', 'done' => (float) $home->rent > 0 && $home->available_from !== null],
            ['label' => 'Area and address', 'done' => $home->zone_id !== null && filled($home->address_line)],
            ['label' => "Main photo and at least {$min} more", 'done' => $home->cover_image !== null && count((array) $home->photos) >= $min],
        ];
    }

    public function render()
    {
        $halls = $this->halls();
        $first = $halls->first();

        $homes = \App\Models\HouseListing::with('franchise')->where('provider_id', $this->partner()->id)->orderBy('id')->get();

        return view('livewire.venue.dashboard', [
            'halls' => $halls,
            'homes' => $homes,
            'homesLive' => $homes->isNotEmpty() && \App\Support\RentalKinds::active(\App\Support\RentalKinds::PROPERTIES, $homes->first()->scope()),
            'partner' => $this->partner(),
            'moduleLive' => $first ? \App\Support\RentalKinds::active(\App\Support\RentalKinds::HALLS, $first->scope()) : false,
        ])->layout('components.layouts.venue', ['title' => 'Home']);
    }
}
