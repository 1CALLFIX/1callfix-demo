<?php

namespace App\Livewire\BookingDisputes;

use App\Models\BookingDispute;
use App\Services\BookingDisputeService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A2 — the admin queue of post-payment pricing disputes. Shows only the disputes the viewer's grant covers;
 * every action re-checks permission, scope, limits and maker-checker in BookingDisputeService.
 */
class Index extends Component
{
    use WithPagination;

    /** 'open' (needs a person) | 'all' | 'resolved' */
    public string $statusFilter = 'open';

    public ?int $actingId = null;

    /** 'resolve' | 'request' | 'approve' | 'reject' */
    public string $actingType = '';

    public string $reason = '';

    public string $outcome = 'no_change';

    public string $refundAmount = '';

    public string $flashType = 'success';

    public string $flashMessage = '';

    public function mount(): void
    {
        abort_unless(app(BookingDisputeService::class)->canEnter(auth()->user()), 403, 'You do not have permission to handle booking disputes.');
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function startAction(int $id, string $type): void
    {
        abort_unless(in_array($type, ['resolve', 'request', 'approve', 'reject'], true), 422);

        $row = $this->visibleRow($id);
        $service = app(BookingDisputeService::class);
        abort_unless($type === 'reject'
            ? $service->canReject($row, auth()->user())
            : $service->availableAction($row, auth()->user()) === $type, 403);

        $this->actingId = $row->id;
        $this->actingType = $type;
        $this->reason = '';
        $this->outcome = 'no_change';
        $this->refundAmount = '';
        $this->resetValidation();
    }

    public function cancelAction(): void
    {
        $this->reset('actingId', 'actingType', 'reason', 'outcome', 'refundAmount');
    }

    public function submitAction(): void
    {
        $row = $this->visibleRow((int) $this->actingId);
        $service = app(BookingDisputeService::class);

        $this->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], [], ['reason' => 'reason']);

        $result = match ($this->actingType) {
            'resolve' => $service->resolve($row, auth()->user(), $this->outcome, $this->reason, $this->refundAmount !== '' ? (float) $this->refundAmount : null),
            'request' => $service->requestRefund($row, auth()->user(), $this->reason),
            'approve' => $service->approveRefund($row, auth()->user(), $this->reason),
            'reject' => $service->rejectRefund($row, auth()->user(), $this->reason),
            default => abort(422),
        };

        $this->flashType = $result['ok'] ? 'success' : 'error';
        $this->flashMessage = $result['message'];
        $this->cancelAction();
    }

    public function retry(int $id): void
    {
        $result = app(BookingDisputeService::class)->retry($this->visibleRow($id), auth()->user());
        $this->flashType = $result['ok'] ? 'success' : 'error';
        $this->flashMessage = $result['message'];
    }

    /** A dispute outside the viewer's scope is a 404, never a 403 that confirms it exists. */
    private function visibleRow(int $id): BookingDispute
    {
        return app(BookingDisputeService::class)->visibleTo(BookingDispute::query(), auth()->user())->findOrFail($id);
    }

    public function render()
    {
        $service = app(BookingDisputeService::class);
        $user = auth()->user();

        $query = $service->visibleTo(BookingDispute::query()->with(['booking:id,code,service_id', 'customer:id,name', 'franchise:id,name', 'requestedBy:id,name', 'approvedBy:id,name']), $user);

        match ($this->statusFilter) {
            'all' => null,
            'resolved' => $query->where('status', BookingDispute::RESOLVED),
            default => $query->where(fn ($q) => $q->where('status', BookingDispute::OPEN)->orWhereIn('refund_status', BookingDispute::REFUND_OPEN)),
        };

        $rows = $query->orderBy('created_at')->paginate(20);

        return view('livewire.booking-disputes.index', [
            'rows' => $rows,
            'actions' => $rows->getCollection()->mapWithKeys(fn ($r) => [$r->id => $service->availableAction($r, $user)]),
            'rejectable' => $rows->getCollection()->mapWithKeys(fn ($r) => [$r->id => $service->canReject($r, $user)]),
        ])->layout('layouts.admin', ['title' => 'Pricing Disputes']);
    }
}
