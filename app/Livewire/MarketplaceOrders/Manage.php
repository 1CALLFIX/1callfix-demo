<?php

namespace App\Livewire\MarketplaceOrders;

use App\Actions\AdminCancelMarketplaceOrderAction;
use App\Actions\AdvanceMarketplaceOrderAction;
use App\Actions\CompleteMarketplaceOrderAction;
use App\Models\MarketplaceOrder;
use App\Services\AuthorizationService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Phase 24 (Marketplace Foundation) admin screen — oversight/intervention,
 * exact structural mirror of PropertyReservations\Manage: Marketplace is
 * also a genuine self-service browse-and-order domain (real customer API
 * exists), so the admin's real job is viewing/advancing/completing/
 * cancelling on the store's behalf when needed, not order creation.
 */
class Manage extends Component
{
    use WithPagination;

    public string $statusFilter = '';
    public string $search = '';
    public ?int $selectedOrderId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('marketplace_orders.view'), 403, 'You do not have permission to view marketplace orders.');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    private function scopeColumns(): array
    {
        return ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
    }

    private function scopedOrdersQuery()
    {
        return app(AuthorizationService::class)
            ->scopeQuery(MarketplaceOrder::query(), auth()->user(), 'marketplace_orders.view', $this->scopeColumns())
            ->with(['customer', 'store', 'franchise']);
    }

    public function viewOrder(int $orderId): void
    {
        $order = $this->scopedOrdersQuery()->find($orderId);
        abort_if(! $order, 404, 'Order not found, or you do not have access to it.');

        $this->selectedOrderId = $orderId;
    }

    public function backToList(): void
    {
        $this->selectedOrderId = null;
    }

    private function assertCanManage(): MarketplaceOrder
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('marketplace_orders.view'), 403);

        $order = $this->scopedOrdersQuery()->find($this->selectedOrderId);
        abort_if(! $order, 404);

        return $order;
    }

    public function advance(string $toStatus, AdvanceMarketplaceOrderAction $action): void
    {
        $order = $this->assertCanManage();

        try {
            $action->execute($order->id, $toStatus);
            session()->flash('message', "Order marked {$toStatus}.");
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    /** Admin completing a `pickup` order on the store's behalf (the customer collected in person) -- no worker/OTP involved, same as CompleteMarketplaceOrderAction's own pickup path. */
    public function completePickup(CompleteMarketplaceOrderAction $action): void
    {
        $order = $this->assertCanManage();

        try {
            $action->execute($order->id);
            session()->flash('message', 'Order marked complete.');
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function cancelOrder(AdminCancelMarketplaceOrderAction $action): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('marketplace_orders.cancel'), 403);
        $order = $this->assertCanManage();

        try {
            $action->execute($order->id, 'Cancelled by admin');
            session()->flash('message', 'Order cancelled.');
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function getSelectedOrderProperty(): ?MarketplaceOrder
    {
        if (! $this->selectedOrderId) {
            return null;
        }

        return $this->scopedOrdersQuery()->with(['statusHistory', 'items'])->find($this->selectedOrderId);
    }

    public function render()
    {
        if ($this->selectedOrder) {
            return view('livewire.marketplace-orders.manage', ['order' => $this->selectedOrder])->layout('layouts.admin', ['title' => 'Marketplace Orders']);
        }

        $orders = $this->scopedOrdersQuery()
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($qq) => $qq
                ->where('code', 'like', "%{$this->search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$this->search}%"))))
            ->latest('id')
            ->paginate(20);

        return view('livewire.marketplace-orders.manage', ['order' => null, 'orders' => $orders])
            ->layout('layouts.admin', ['title' => 'Marketplace Orders']);
    }
}
