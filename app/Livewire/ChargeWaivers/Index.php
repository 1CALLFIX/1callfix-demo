<?php

namespace App\Livewire\ChargeWaivers;

use App\Models\ChargeWaiver;
use App\Services\ChargeWaiverService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * F1/F2 — the approval queue for waived customer charges. Shows only the waivers the viewer's grant covers; every
 * action re-checks permission, scope, limits and maker-checker in ChargeWaiverService.
 */
class Index extends Component
{
    use WithPagination;

    /** 'open' (needs a person) | 'all' */
    public string $statusFilter = 'open';

    public ?int $actingId = null;

    /** 'approve' | 'reject' */
    public string $actingType = '';

    public string $reason = '';

    public string $flashType = 'success';

    public string $flashMessage = '';

    public function mount(): void
    {
        abort_unless(app(ChargeWaiverService::class)->canEnter(auth()->user()), 403, 'You do not have permission to handle charge waivers.');
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function startAction(int $id, string $type): void
    {
        abort_unless(in_array($type, ['approve', 'reject'], true), 422);
        abort_unless(app(ChargeWaiverService::class)->availableAction($this->visibleRow($id), auth()->user()) === 'approve', 403);

        $this->actingId = $id;
        $this->actingType = $type;
        $this->reason = '';
        $this->resetValidation();
    }

    public function cancelAction(): void
    {
        $this->reset('actingId', 'actingType', 'reason');
    }

    public function submitAction(): void
    {
        $row = $this->visibleRow((int) $this->actingId);
        $service = app(ChargeWaiverService::class);

        $this->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], [], ['reason' => 'reason']);

        $result = match ($this->actingType) {
            'approve' => $service->approve($row, auth()->user(), $this->reason),
            'reject' => $service->reject($row, auth()->user(), $this->reason),
            default => abort(422),
        };

        $this->flashType = $result['ok'] ? 'success' : 'error';
        $this->flashMessage = $result['message'];
        $this->cancelAction();
    }

    public function retry(int $id): void
    {
        $result = app(ChargeWaiverService::class)->retry($this->visibleRow($id), auth()->user());
        $this->flashType = $result['ok'] ? 'success' : 'error';
        $this->flashMessage = $result['message'];
    }

    /** A waiver outside the viewer's scope is a 404, never a 403 that confirms it exists. */
    private function visibleRow(int $id): ChargeWaiver
    {
        return app(ChargeWaiverService::class)->visibleTo(ChargeWaiver::query(), auth()->user())->findOrFail($id);
    }

    public function render()
    {
        $service = app(ChargeWaiverService::class);
        $user = auth()->user();

        $query = $service->visibleTo(ChargeWaiver::query()->with(['booking:id,code,customer_id', 'booking.customer:id,name', 'franchise:id,name', 'requestedBy:id,name', 'approvedBy:id,name']), $user);
        if ($this->statusFilter !== 'all') {
            $query->whereIn('status', [ChargeWaiver::AWAITING, ChargeWaiver::FAILED]);
        }

        $rows = $query->orderBy('created_at')->paginate(20);

        return view('livewire.charge-waivers.index', [
            'rows' => $rows,
            'actions' => $rows->getCollection()->mapWithKeys(fn ($r) => [$r->id => $service->availableAction($r, $user)]),
        ])->layout('layouts.admin', ['title' => 'Charge Waivers']);
    }
}
