<?php

namespace App\Livewire\Halls;

use App\Livewire\Venue\HallEditor;
use App\Models\Hall;
use App\Services\ActivityLogger;
use App\Services\AuthorizationService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin → Rental → Function halls (R1, owner 2026-11-13). The team approves every hall before customers see it, and
 * every change to a live hall's name, address, photos or description (the hall stays bookable meanwhile). Approve /
 * Send back (with a reason the owner sees) / Feature / Switch off. Row scope: `halls.manage` on the hall's own zone /
 * franchise / city / country.
 */
class Manage extends Component
{
    use WithPagination;

    #[Url(as: 'status')]
    public string $statusFilter = 'in_review';

    public ?int $selectedHallId = null;

    public string $reason = '';

    private const COLUMNS = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('halls.manage'), 403, 'You do not have permission to manage function halls.');
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
        $this->reset('selectedHallId', 'reason');
    }

    private function halls()
    {
        return app(AuthorizationService::class)->scopeQuery(Hall::query(), auth()->user(), 'halls.manage', self::COLUMNS);
    }

    private function find(int $id): Hall
    {
        $hall = $this->halls()->with('slots')->find($id);
        abort_if(! $hall, 404);

        return $hall;
    }

    public function viewHall(int $id): void
    {
        $this->find($id);
        $this->selectedHallId = $id;
        $this->reset('reason');
    }

    public function backToList(): void
    {
        $this->reset('selectedHallId', 'reason');
    }

    public function approve(int $id): void
    {
        $hall = $this->find($id);
        if ($hall->status !== 'in_review') {
            session()->flash('error', 'Only a hall waiting for review can be approved.');

            return;
        }
        $problems = HallEditor::submitProblems($hall);
        if ($problems !== []) {
            session()->flash('error', 'Not ready to approve: '.implode(' ', $problems));

            return;
        }
        $hall->update(['status' => 'approved', 'approved_at' => now(), 'rejection_reason' => null, 'is_active' => true]);
        ActivityLogger::logModel(auth()->user(), $hall, 'Hall approved (admin)');
        session()->flash('message', "{$hall->name} approved.");
    }

    public function sendBack(int $id): void
    {
        $hall = $this->find($id);
        if (! in_array($hall->status, ['in_review', 'approved'], true)) {
            session()->flash('error', 'This hall is not waiting for review.');

            return;
        }
        $this->validate(['reason' => 'required|string|min:5|max:500'], ['reason.required' => 'Tell the owner what to change.']);
        $hall->update(['status' => 'rejected', 'rejection_reason' => trim($this->reason), 'approved_at' => null, 'is_active' => false]);
        ActivityLogger::logModel(auth()->user(), $hall, 'Hall sent back for changes (admin)', ['reason' => trim($this->reason)]);
        $this->reset('reason');
        session()->flash('message', "{$hall->name} sent back to the owner with your reason.");
    }

    /** A live hall's waiting change: the new name / address / photos / description replace the old ones. */
    public function approveChanges(int $id): void
    {
        $hall = $this->find($id);
        $changes = (array) $hall->pending_changes;
        if ($changes === []) {
            return;
        }
        // The public link stays the same when the name changes (old links never break).
        $hall->update(array_intersect_key($changes, array_flip(Hall::REVIEWED)) + ['pending_changes' => null, 'pending_requested_at' => null, 'pending_rejection' => null]);
        ActivityLogger::logModel(auth()->user(), $hall, 'Hall change approved (admin)', ['fields' => array_keys($changes)]);
        session()->flash('message', "Change to {$hall->name} approved. Customers see it now.");
    }

    public function rejectChanges(int $id): void
    {
        $hall = $this->find($id);
        if ($hall->pending_changes === null) {
            return;
        }
        $this->validate(['reason' => 'required|string|min:5|max:500'], ['reason.required' => 'Tell the owner why.']);
        $hall->update(['pending_changes' => null, 'pending_requested_at' => null, 'pending_rejection' => trim($this->reason)]);
        ActivityLogger::logModel(auth()->user(), $hall, 'Hall change not accepted (admin)', ['reason' => trim($this->reason)]);
        $this->reset('reason');
        session()->flash('message', "Change to {$hall->name} not accepted. The owner sees your reason.");
    }

    public function toggleFeatured(int $id): void
    {
        $hall = $this->find($id);
        $hall->update(['is_featured' => ! $hall->is_featured]);
        ActivityLogger::logModel(auth()->user(), $hall, $hall->is_featured ? 'Hall featured (admin)' : 'Hall no longer featured (admin)');
    }

    /** Stop bookings at once (e.g. a complaint); the owner can switch it on again only after talking to the team. */
    public function toggleActive(int $id): void
    {
        $hall = $this->find($id);
        if ($hall->status !== 'approved') {
            return;
        }
        $hall->update(['is_active' => ! $hall->is_active]);
        ActivityLogger::logModel(auth()->user(), $hall, $hall->is_active ? 'Hall switched on (admin)' : 'Hall switched off (admin)');
    }

    public function render()
    {
        $data = [
            'inReview' => $this->halls()->where('status', 'in_review')->count(),
            'changesWaiting' => $this->halls()->whereNotNull('pending_changes')->count(),
        ];

        if ($this->selectedHallId) {
            $data['hall'] = $this->halls()->with(['provider.user', 'slots', 'franchise'])->findOrFail($this->selectedHallId);
        } else {
            $data['halls'] = $this->halls()->with(['provider.user', 'franchise', 'slots'])
                ->when($this->statusFilter === 'changes', fn ($q) => $q->whereNotNull('pending_changes'))
                ->when(array_key_exists($this->statusFilter, Hall::STATUS_LABELS), fn ($q) => $q->where('status', $this->statusFilter))
                ->orderByRaw("status = 'in_review' desc")->latest('id')->paginate(20);
        }

        return view('livewire.halls.manage', $data)->layout('layouts.admin', ['title' => 'Function halls']);
    }
}
