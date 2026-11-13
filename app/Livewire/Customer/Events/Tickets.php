<?php

namespace App\Livewire\Customer\Events;

use App\Models\EventOrder;
use App\Support\SiteText;
use Livewire\Component;

/** Events E2 — /events/tickets (signed in): the customer's ticket orders, upcoming first. */
class Tickets extends Component
{
    public function render()
    {
        $orders = EventOrder::with(['event.franchise'])->where('customer_id', auth()->id())
            ->whereIn('status', ['pending', 'confirmed'])->latest('id')->limit(50)->get()
            ->sortBy(fn ($o) => [$o->event->ends_at->isPast() ? 1 : 0, $o->event->starts_at->timestamp]);
        $tz = app(\App\Services\TimezoneResolver::class)->platformTimezone();

        return view('livewire.customer.events.tickets', ['orders' => $orders, 'tz' => $tz])
            ->layout('components.layouts.customer', ['title' => SiteText::get('event.tickets.title'), 'indexable' => false]);
    }
}
