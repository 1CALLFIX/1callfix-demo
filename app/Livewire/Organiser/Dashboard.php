<?php

namespace App\Livewire\Organiser;

use App\Livewire\Organiser\Concerns\InteractsWithOrganiser;
use App\Models\EventOrganiser;
use App\Services\ModuleActivationService;
use App\Support\Modules;
use Livewire\Component;

/**
 * Organiser portal home (Events E1): where the organiser stands (waiting for approval / approved / not approved), a
 * short checklist before approval, and its events by status.
 */
class Dashboard extends Component
{
    use InteractsWithOrganiser;

    /** @return list<array{label: string, done: bool, route: string}> */
    public static function checklist(EventOrganiser $organiser): array
    {
        return [
            ['label' => 'About you (a few lines buyers will read)', 'done' => filled($organiser->about), 'route' => 'organiser.details'],
            ['label' => 'Your first event with at least one ticket type', 'done' => $organiser->events()->whereHas('ticketTypes')->exists(), 'route' => 'organiser.events'],
        ];
    }

    public function render()
    {
        $organiser = $this->organiser();
        $checklist = self::checklist($organiser);

        return view('livewire.organiser.dashboard', [
            'organiser' => $organiser,
            'checklist' => $checklist,
            'ready' => collect($checklist)->every('done'),
            'moduleLive' => app(ModuleActivationService::class)->isActive(Modules::EVENTS, $organiser->scope()),
            'counts' => $organiser->events()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
            'next' => $organiser->events()->whereIn('status', ['approved', 'in_review'])->where('starts_at', '>', now())->orderBy('starts_at')->first(),
            'tz' => app(\App\Services\TimezoneResolver::class)->platformTimezone(),
        ])->layout('components.layouts.organiser', ['title' => 'Home']);
    }
}
