<?php

namespace App\Livewire\ParcelClaims;

use App\Models\ParcelProtectionClaim;
use App\Services\Parcel\ParcelProtectionClaimService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin → Parcel Claims (Stage 2, owner decision 2026-10-31): the queue for parcel protection claims and their
 * payouts — age, amount, franchise, status, escalation level. Decide (accept with the amount, or decline), then the
 * payout under the approval model (request → approve by a different user within their limit, reject, retry). Each
 * row only offers what ParcelProtectionClaimService::availableAction() allows, and every action re-checks server-side.
 */
class Index extends Component
{
    use WithPagination;

    public string $statusFilter = 'open';

    public ?int $actingId = null;

    public string $note = '';

    public string $amount = '';

    public string $flashType = 'success';

    public string $flashMessage = '';

    public function mount(ParcelProtectionClaimService $service): void
    {
        abort_unless($service->canEnter(auth()->user()), 403);
    }

    private function find(int $id, ParcelProtectionClaimService $service): ParcelProtectionClaim
    {
        $row = $service->visibleTo(ParcelProtectionClaim::query(), auth()->user())->find($id);
        abort_unless($row, 404);

        return $row;
    }

    public function open(int $id, ParcelProtectionClaimService $service): void
    {
        $row = $this->find($id, $service);
        $this->actingId = $row->id;
        $this->note = '';
        $this->amount = (string) (float) $row->payout_amount;
    }

    public function act(string $what, ParcelProtectionClaimService $service): void
    {
        $row = $this->find((int) $this->actingId, $service);
        $user = auth()->user();
        $result = match ($what) {
            'accept' => $service->decide($row, $user, true, $this->note, $this->amount === '' ? null : (float) $this->amount),
            'decline' => $service->decide($row, $user, false, $this->note),
            'request' => $service->requestPayout($row, $user, $this->note),
            'approve' => $service->approvePayout($row, $user, $this->note),
            'reject' => $service->rejectPayout($row, $user, $this->note),
            'retry' => $service->retry($row, $user),
            default => ['ok' => false, 'message' => 'Unknown action.'],
        };
        $this->flashType = $result['ok'] ? 'success' : 'error';
        $this->flashMessage = $result['message'];
        if ($result['ok']) {
            $this->actingId = null;
        }
    }

    public function render(ParcelProtectionClaimService $service)
    {
        $query = $service->visibleTo(ParcelProtectionClaim::query(), auth()->user())
            ->with(['order:id,code,declared_value,protection_fee,payment_method', 'customer:id,name', 'franchise:id,name']);
        $query = match ($this->statusFilter) {
            'open' => $query->whereIn('status', ParcelProtectionClaim::OPEN),
            'paid' => $query->where('status', ParcelProtectionClaim::PAID),
            'declined' => $query->where('status', ParcelProtectionClaim::DECLINED),
            default => $query,
        };
        $rows = $query->orderByDesc('escalation_level')->oldest('id')->paginate(25);

        return view('livewire.parcel-claims.index', [
            'rows' => $rows,
            'actions' => $rows->getCollection()->mapWithKeys(fn ($c) => [$c->id => $service->availableAction($c, auth()->user())]),
        ])->layout('layouts.admin', ['title' => 'Parcel Claims']);
    }
}
