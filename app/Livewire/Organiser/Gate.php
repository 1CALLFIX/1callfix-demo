<?php

namespace App\Livewire\Organiser;

use App\Livewire\Organiser\Concerns\InteractsWithOrganiser;
use App\Services\Events\EventTicketService;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Organiser portal → an event → Gate (Events E3): scan a ticket's QR with the phone camera, or type its code. A ticket
 * of this event, paid and not used, lets its people in once (green); anything else is red with the reason. Shows how
 * many have come in so far.
 */
class Gate extends Component
{
    use InteractsWithOrganiser;

    #[Locked]
    public int $eventId = 0;

    public string $code = '';

    /** @var array{ok: bool, message: string}|null */
    public ?array $result = null;

    public function mount(int $eventId): void
    {
        $event = $this->ownEvent($eventId);
        abort_unless(in_array($event->status, ['approved', 'completed'], true), 404);
        $this->eventId = $event->id;
    }

    public function check(EventTicketService $tickets): void
    {
        $r = $tickets->checkIn($this->ownEvent($this->eventId), $this->code, auth()->user());
        $this->result = ['ok' => $r['ok'], 'message' => $r['message']];
        $this->reset('code');
    }

    /** From the camera: the QR holds only the ticket code. */
    public function scanned(string $code, EventTicketService $tickets): void
    {
        $this->code = mb_substr($code, 0, 40);
        $this->check($tickets);
    }

    public function render()
    {
        $event = $this->ownEvent($this->eventId);

        return view('livewire.organiser.gate', [
            'event' => $event,
            'sales' => EventTicketService::salesSummary($event->load('ticketTypes')),
        ])->layout('components.layouts.organiser', ['title' => 'Gate · '.$event->title]);
    }
}
