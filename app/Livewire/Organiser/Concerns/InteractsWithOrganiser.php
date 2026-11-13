<?php

namespace App\Livewire\Organiser\Concerns;

use App\Models\Event;
use App\Models\EventOrganiser;
use App\Models\Provider;

/** The signed-in organiser's own business and events — never another organiser's (404). */
trait InteractsWithOrganiser
{
    protected function partner(): Provider
    {
        return Provider::with('user')->where('user_id', auth()->id())->firstOrFail();
    }

    protected function organiser(): EventOrganiser
    {
        return EventOrganiser::with('franchise')->where('provider_id', $this->partner()->id)->orderBy('id')->firstOrFail();
    }

    protected function ownEvent(int $eventId): Event
    {
        return Event::where('event_organiser_id', $this->organiser()->id)->findOrFail($eventId);
    }
}
