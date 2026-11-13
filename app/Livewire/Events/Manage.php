<?php

namespace App\Livewire\Events;

use App\Livewire\Organiser\EventEditor;
use App\Models\Event;
use App\Models\EventOrganiser;
use App\Services\ActivityLogger;
use App\Services\AuthorizationService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin → Events & organisers (Events E1, owner 2026-11-13). The team approves every organiser and every event before
 * tickets can be sold (the risk is fake events). Approve / Not approved (with a reason the organiser sees) / Featured.
 * Row scope: `events.manage` on the organiser's or event's own zone / franchise / city / country.
 */
class Manage extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'events';

    #[Url(as: 'status')]
    public string $statusFilter = 'in_review';

    #[Url(as: 'approval')]
    public string $approvalFilter = '';

    public ?int $selectedEventId = null;

    public string $rejectReason = '';

    /** Events E3: the reason buyers see when the team cancels an event. */
    public string $cancelReason = '';

    private const COLUMNS = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('events.manage'), 403, 'You do not have permission to manage events.');
        $this->tab = in_array($this->tab, ['events', 'organisers'], true) ? $this->tab : 'events';
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['events', 'organisers'], true) ? $tab : 'events';
        $this->reset('selectedEventId', 'rejectReason');
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingApprovalFilter(): void
    {
        $this->resetPage();
    }

    private function events()
    {
        return app(AuthorizationService::class)->scopeQuery(Event::query(), auth()->user(), 'events.manage', self::COLUMNS);
    }

    private function organisers()
    {
        return app(AuthorizationService::class)->scopeQuery(EventOrganiser::query(), auth()->user(), 'events.manage', self::COLUMNS);
    }

    // ------------------------------------------------------------------ organisers

    public function approveOrganiser(int $id): void
    {
        $organiser = $this->organisers()->find($id);
        abort_if(! $organiser, 404);

        $organiser->update(['approval_status' => 'approved', 'approved_at' => now(), 'rejection_reason' => null, 'is_active' => true]);
        ActivityLogger::logModel(auth()->user(), $organiser, 'Event organiser approved (admin)');
        session()->flash('message', "{$organiser->name} approved. Their events can now be approved.");
    }

    public function rejectOrganiser(int $id): void
    {
        $organiser = $this->organisers()->find($id);
        abort_if(! $organiser, 404);
        $this->validate(['rejectReason' => 'required|string|min:5|max:500'], ['rejectReason.required' => 'Tell the organiser what to fix.']);

        $organiser->update(['approval_status' => 'rejected', 'rejection_reason' => trim($this->rejectReason), 'is_active' => false]);
        ActivityLogger::logModel(auth()->user(), $organiser, 'Event organiser not approved (admin)', ['reason' => trim($this->rejectReason)]);
        $this->reset('rejectReason');
        session()->flash('message', "{$organiser->name} marked not approved. They see your reason in their portal.");
    }

    // ------------------------------------------------------------------ events

    public function viewEvent(int $id): void
    {
        abort_if(! $this->events()->whereKey($id)->exists(), 404);
        $this->selectedEventId = $id;
        $this->reset('rejectReason');
    }

    public function backToList(): void
    {
        $this->reset('selectedEventId', 'rejectReason');
    }

    public function approveEvent(int $id): void
    {
        $event = $this->events()->with('organiser')->find($id);
        abort_if(! $event, 404);
        if ($event->status !== 'in_review') {
            session()->flash('error', 'Only an event waiting for review can be approved.');

            return;
        }
        $problems = EventEditor::submitProblems($event);
        if ($event->organiser?->approval_status !== 'approved') {
            $problems[] = 'Approve the organiser first.';
        }
        if ($problems !== []) {
            session()->flash('error', 'Not ready to approve: '.implode(' ', $problems));

            return;
        }

        $event->update(['status' => 'approved', 'approved_at' => now(), 'rejection_reason' => null]);
        ActivityLogger::logModel(auth()->user(), $event, 'Event approved (admin)');
        session()->flash('message', "{$event->title} approved.");
    }

    public function rejectEvent(int $id): void
    {
        $event = $this->events()->find($id);
        abort_if(! $event, 404);
        if (! in_array($event->status, ['in_review', 'approved'], true)) {
            session()->flash('error', 'This event is not waiting for review.');

            return;
        }
        $this->validate(['rejectReason' => 'required|string|min:5|max:500'], ['rejectReason.required' => 'Tell the organiser what to change.']);

        $event->update(['status' => 'rejected', 'rejection_reason' => trim($this->rejectReason), 'approved_at' => null]);
        ActivityLogger::logModel(auth()->user(), $event, 'Event sent back for changes (admin)', ['reason' => trim($this->rejectReason)]);
        $this->reset('rejectReason');
        session()->flash('message', "{$event->title} sent back to the organiser with your reason.");
    }

    /** Events E3: the team cancels an event (e.g. the organiser cannot be reached); every buyer is refunded in full. */
    public function cancelEvent(int $id, \App\Services\Events\EventTicketService $tickets): void
    {
        $event = $this->events()->find($id);
        abort_if(! $event, 404);
        $this->validate(['cancelReason' => 'required|string|min:5|max:500'], ['cancelReason.required' => 'Write the reason buyers will see.']);
        try {
            $n = $tickets->cancelEvent($event, auth()->user(), $this->cancelReason);
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }
        $this->reset('cancelReason');
        session()->flash('message', "{$event->title} cancelled. {$n} ".\Illuminate\Support\Str::plural('order', $n).' refunded in full.');
    }

    public function toggleFeatured(int $id): void
    {
        $event = $this->events()->find($id);
        abort_if(! $event, 404);
        $event->update(['is_featured' => ! $event->is_featured]);
        ActivityLogger::logModel(auth()->user(), $event, $event->is_featured ? 'Event featured (admin)' : 'Event no longer featured (admin)');
    }

    public function render()
    {
        $tz = app(\App\Services\TimezoneResolver::class)->platformTimezone();
        $data = [
            'tz' => $tz,
            'pendingOrganisers' => $this->organisers()->where('approval_status', 'pending')->count(),
            'inReview' => $this->events()->where('status', 'in_review')->count(),
        ];

        if ($this->tab === 'organisers') {
            $data['organisers'] = $this->organisers()->with(['provider.user', 'franchise'])->withCount('events')
                ->when(in_array($this->approvalFilter, ['pending', 'approved', 'rejected'], true), fn ($q) => $q->where('approval_status', $this->approvalFilter))
                ->orderByRaw("approval_status = 'pending' desc")->latest('id')->paginate(20);
        } elseif ($this->selectedEventId) {
            $data['event'] = $this->events()->with(['organiser.provider', 'ticketTypes', 'franchise'])->findOrFail($this->selectedEventId);
            $data['sales'] = \App\Services\Events\EventTicketService::salesSummary($data['event']);
        } else {
            $data['events'] = $this->events()->with('organiser')->withSum('ticketTypes as tickets_total', 'quantity')
                ->when(array_key_exists($this->statusFilter, Event::STATUS_LABELS), fn ($q) => $q->where('status', $this->statusFilter))
                ->orderBy('starts_at')->paginate(20);
        }

        return view('livewire.events.manage', $data)->layout('layouts.admin', ['title' => 'Events & organisers']);
    }
}
