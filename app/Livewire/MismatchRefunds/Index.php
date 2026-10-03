<?php

namespace App\Livewire\MismatchRefunds;

use App\Models\MismatchRefund;
use App\Services\Payments\MismatchRefundService;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The one queue for Razorpay captures whose amount did not match (MANUAL
 * MONEY ACTIONS model). Shows only the rows the viewer's grant covers
 * (franchise holders their franchise, HQ all); every action re-checks
 * permission, scope, limits and maker-checker in MismatchRefundService, so a
 * direct Livewire call is refused, not just a hidden button.
 */
class Index extends Component
{
    use WithPagination;

    /** 'open' | 'all' | a MismatchRefund status */
    public string $statusFilter = 'open';

    public ?int $actingId = null;

    /** 'request' | 'approve' | 'reject' */
    public string $actingType = '';

    public string $reason = '';

    public string $flashType = 'success';

    public string $flashMessage = '';

    public function mount(): void
    {
        $service = app(MismatchRefundService::class);

        abort_unless($service->canEnter(auth()->user()), 403, 'You do not have permission to handle mismatch refunds.');

        // Backfill mismatches logged before the queue existed (cheap, at most every 5 minutes).
        if (Cache::add('mismatch-refund-sync', true, now()->addMinutes(5))) {
            $service->syncFromLogs();
        }
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function startAction(int $id, string $type): void
    {
        abort_unless(in_array($type, ['request', 'approve', 'reject'], true), 422);

        $row = $this->visibleRow($id);
        $service = app(MismatchRefundService::class);
        abort_unless($type === 'reject'
            ? $service->canReject($row, auth()->user())
            : $service->availableAction($row, auth()->user()) === $type, 403);

        $this->actingId = $row->id;
        $this->actingType = $type;
        $this->reason = '';
        $this->resetValidation();
    }

    public function cancelAction(): void
    {
        $this->actingId = null;
        $this->actingType = '';
        $this->reason = '';
    }

    public function submitAction(): void
    {
        $row = $this->visibleRow((int) $this->actingId);
        $service = app(MismatchRefundService::class);

        $this->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], [], ['reason' => 'reason']);

        $result = match ($this->actingType) {
            'request' => $service->request($row, auth()->user(), $this->reason),
            'approve' => $service->approve($row, auth()->user(), $this->reason),
            'reject' => $service->reject($row, auth()->user(), $this->reason),
            default => abort(422),
        };

        $this->flash($result);
        $this->cancelAction();
    }

    public function retry(int $id): void
    {
        $this->flash(app(MismatchRefundService::class)->retry($this->visibleRow($id), auth()->user()));
    }

    private function flash(array $result): void
    {
        $this->flashType = $result['ok'] ? 'success' : 'error';
        $this->flashMessage = $result['message'];
    }

    /** A row outside the viewer's scope is a 404, never a 403 that confirms it exists. */
    private function visibleRow(int $id): MismatchRefund
    {
        return app(MismatchRefundService::class)->visibleTo(MismatchRefund::query(), auth()->user())->findOrFail($id);
    }

    public function render()
    {
        $service = app(MismatchRefundService::class);
        $user = auth()->user();

        $query = $service->visibleTo(MismatchRefund::query()->with(['franchise', 'requestedBy', 'approvedBy']), $user);

        match ($this->statusFilter) {
            'all' => null,
            'open' => $query->whereIn('status', MismatchRefund::OPEN),
            default => $query->where('status', $this->statusFilter),
        };

        $rows = $query->orderByRaw("CASE WHEN status IN ('awaiting_request','awaiting_approval','failed') THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->paginate(20);

        $actions = $rows->getCollection()->mapWithKeys(fn ($row) => [$row->id => $service->availableAction($row, $user)]);

        $rejectable = $rows->getCollection()->mapWithKeys(fn ($row) => [$row->id => $service->canReject($row, $user)]);

        return view('livewire.mismatch-refunds.index', ['rows' => $rows, 'actions' => $actions, 'rejectable' => $rejectable])
            ->layout('layouts.admin', ['title' => 'Mismatch Refunds']);
    }
}
