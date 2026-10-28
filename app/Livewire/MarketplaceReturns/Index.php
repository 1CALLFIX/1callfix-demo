<?php

namespace App\Livewire\MarketplaceReturns;

use App\Models\MarketplaceReturn;
use App\Services\Marketplace\MarketplaceReturnService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin → Shop Returns (E-commerce Phase 3b): the queue for returns and their refunds — age, amount, franchise,
 * status, escalation level. Decide (accept with the refund amount, or decline), mark the item received, then the
 * refund under the approval model (request → approve by a different user within their limit, reject, retry). Each
 * row only offers what MarketplaceReturnService::availableAction() allows, and every action re-checks server-side.
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

    public function mount(MarketplaceReturnService $service): void
    {
        abort_unless($service->canEnter(auth()->user()), 403);
    }

    private function find(int $id, MarketplaceReturnService $service): MarketplaceReturn
    {
        $row = $service->visibleTo(MarketplaceReturn::query(), auth()->user())->find($id);
        abort_unless($row, 404);

        return $row;
    }

    public function open(int $id, MarketplaceReturnService $service): void
    {
        $row = $this->find($id, $service);
        $this->actingId = $row->id;
        $this->note = '';
        $this->amount = (string) (float) $row->refund_amount;
    }

    public function act(string $what, MarketplaceReturnService $service): void
    {
        $row = $this->find((int) $this->actingId, $service);
        $user = auth()->user();
        $result = match ($what) {
            'accept' => $service->decide($row, $user, true, $this->note, $this->amount === '' ? null : (float) $this->amount),
            'decline' => $service->decide($row, $user, false, $this->note),
            'receive' => $service->markReceived($row, $user),
            'request' => $service->requestRefund($row, $user, $this->note),
            'approve' => $service->approveRefund($row, $user, $this->note),
            'reject' => $service->rejectRefund($row, $user, $this->note),
            'retry' => $service->retry($row, $user),
            default => ['ok' => false, 'message' => 'Unknown action.'],
        };
        $this->flashType = $result['ok'] ? 'success' : 'error';
        $this->flashMessage = $result['message'];
        if ($result['ok']) {
            $this->actingId = null;
        }
    }

    public function render(MarketplaceReturnService $service)
    {
        $query = $service->visibleTo(MarketplaceReturn::query(), auth()->user())
            ->with(['order:id,code,payment_method,total_amount', 'store:id,name', 'customer:id,name', 'franchise:id,name']);
        $query = match ($this->statusFilter) {
            'open' => $query->whereIn('status', MarketplaceReturn::OPEN),
            'refunded' => $query->where('status', MarketplaceReturn::REFUNDED),
            'declined' => $query->where('status', MarketplaceReturn::DECLINED),
            default => $query,
        };
        $rows = $query->orderByDesc('escalation_level')->oldest('id')->paginate(25);

        return view('livewire.marketplace-returns.index', [
            'rows' => $rows,
            'actions' => $rows->getCollection()->mapWithKeys(fn ($r) => [$r->id => $service->availableAction($r, auth()->user())]),
        ])->layout('layouts.admin', ['title' => 'Shop Returns']);
    }
}
