<?php

namespace App\Livewire\Organiser;

use App\Livewire\Organiser\Concerns\InteractsWithOrganiser;
use Livewire\Component;

/** Organiser portal → Events (Events E1): every event of this organiser, latest date first, with its status. */
class Events extends Component
{
    use InteractsWithOrganiser;

    public function render()
    {
        $organiser = $this->organiser();

        return view('livewire.organiser.events', [
            'organiser' => $organiser,
            'events' => $organiser->events()->withCount('ticketTypes')->withSum('ticketTypes as tickets_total', 'quantity')
                ->orderByDesc('starts_at')->get(),
            'tz' => app(\App\Services\TimezoneResolver::class)->platformTimezone(),
        ])->layout('components.layouts.organiser', ['title' => 'Events']);
    }
}
