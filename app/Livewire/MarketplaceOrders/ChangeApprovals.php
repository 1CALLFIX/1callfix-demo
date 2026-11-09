<?php

namespace App\Livewire\MarketplaceOrders;

use App\Models\MarketplaceCategory;
use App\Models\PendingChange;
use App\Models\Product;
use App\Models\Store;
use App\Services\AuthorizationService;
use App\Services\PendingChangeService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin → Changes to approve (owner rule 2026-11-03). Sellers' changes to LIVE items (name, photos, description,
 * category) and shops (address, map pin, GSTIN, PAN). Old and new side by side; Approve = the new details replace
 * the old ones; Reject (reason required, shown to the seller) = nothing changes. Item changes need products.manage,
 * shop changes stores.manage, each within the admin's own scope (franchise / zone / city).
 */
class ChangeApprovals extends Component
{
    use WithPagination;

    public string $tab = 'all'; // all | waiting | decided (owner 2026-11-09: every list opens on All)

    /** pending change id => reason being typed for a rejection. */
    public array $notes = [];

    public string $flashMessage = '';
    public string $flashType = 'success';

    public function mount(): void
    {
        abort_unless($this->canProducts() || $this->canStores(), 403, 'You do not have permission to approve seller changes.');
    }

    private function canProducts(): bool
    {
        return auth()->user()->hasPermissionAnywhere('products.manage');
    }

    private function canStores(): bool
    {
        return auth()->user()->hasPermissionAnywhere('stores.manage');
    }

    /** Changes this admin may decide: item changes with products.manage, shop changes with stores.manage, in scope. */
    private function scoped()
    {
        $auth = app(AuthorizationService::class);
        $columns = ['zone_id' => 'store.zone_id', 'franchise_id' => 'store.franchise_id', 'city_id' => 'store.franchise.city_id', 'country_id' => 'store.franchise.country_id'];
        $product = (new Product)->getMorphClass();
        $store = (new Store)->getMorphClass();

        return PendingChange::query()->where(function ($q) use ($auth, $columns, $product, $store) {
            if ($this->canProducts()) {
                $q->orWhere(fn ($w) => $auth->scopeQuery($w->where('changeable_type', $product), auth()->user(), 'products.manage', $columns));
            }
            if ($this->canStores()) {
                $q->orWhere(fn ($w) => $auth->scopeQuery($w->where('changeable_type', $store), auth()->user(), 'stores.manage', $columns));
            }
            if (! $this->canProducts() && ! $this->canStores()) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    private function own(int $id): PendingChange
    {
        $change = $this->scoped()->find($id);
        abort_unless($change, 404);

        return $change;
    }

    public function approve(int $id, PendingChangeService $service): void
    {
        $change = $this->own($id); // outside the try: a 404 (out of scope) is an HttpException, itself a RuntimeException
        try {
            $service->approve($change, auth()->user());
            $this->flash('Approved. Customers see the new details now.', 'success');
        } catch (\RuntimeException $e) {
            $this->flash($e->getMessage(), 'error');
        }
    }

    public function reject(int $id, PendingChangeService $service): void
    {
        $change = $this->own($id);
        try {
            $service->reject($change, auth()->user(), (string) ($this->notes[$id] ?? ''));
            unset($this->notes[$id]);
            $this->flash('Rejected. Nothing changed; the seller sees your reason.', 'success');
        } catch (\RuntimeException $e) {
            $this->flash($e->getMessage(), 'error');
        }
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    private function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }

    public function render()
    {
        $changes = $this->scoped()
            ->when($this->tab === 'waiting', fn ($q) => $q->pending()->oldest('id'))
            ->when($this->tab === 'decided', fn ($q) => $q->whereIn('status', [PendingChange::APPROVED, PendingChange::REJECTED])->latest('reviewed_at'))
            ->when($this->tab === 'all', fn ($q) => $q->latest('id'))
            ->with(['changeable', 'store', 'requester', 'reviewer'])->paginate(20);
        $categoryIds = $changes->flatMap(fn ($c) => [$c->new_values['marketplace_category_id'] ?? null, $c->old_values['marketplace_category_id'] ?? null])->filter()->unique();

        return view('livewire.marketplace-orders.change-approvals', [
            'changes' => $changes,
            'waitingCount' => $this->scoped()->pending()->count(),
            'categories' => MarketplaceCategory::whereIn('id', $categoryIds)->pluck('name', 'id'),
        ])->layout('layouts.admin', ['title' => 'Changes to approve']);
    }
}
