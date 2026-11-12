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

    /** NO PAYMENT, NO BOOKING (owner 2026-11-07): list unpaid online orders (payment attempts) instead, for support. */
    public bool $attempts = false;
    public string $search = '';

    /** Admin menu 2026-11-03: '' (all) | shop | food — Shop Orders and Food Orders are this screen, filtered. */
    public string $moduleFilter = '';

    protected $queryString = ['moduleFilter' => ['except' => ''], 'search' => ['except' => '']]; // dashboard order codes open here
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

    public function updatingModuleFilter(): void
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
            // NO PAYMENT, NO BOOKING (owner 2026-11-07): unpaid online orders only under "Payment attempts".
            ->when($this->attempts, fn ($q) => $q->paymentAttempts(), fn ($q) => $q->booked())
            ->with(['customer', 'store', 'franchise', 'statusHistory' => \App\Support\Turnaround::historyScope()]);
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

    /** Owner 2026-11-12: written reason for any status change an admin makes for the shop / restaurant / rider. */
    public string $emergencyReason = '';

    public function canEmergencyOverride(MarketplaceOrder $order): bool
    {
        return auth()->user()->hasPermission(\App\Support\AdminOverride::PERMISSION, [
            'zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id,
            'city_id' => $order->franchise?->city_id, 'country_id' => $order->franchise?->country_id,
        ]);
    }

    /**
     * Owner 2026-11-12: every status change an admin makes here is an emergency override: it needs the
     * orders.emergency_override permission for this order's franchise and a written reason, and is logged with the
     * admin's name. (Before, "view orders" alone was enough and the history did not say who did it.)
     */
    private function emergency(callable $run, string $success): void
    {
        $order = $this->assertCanManage();
        abort_unless($this->canEmergencyOverride($order), 403);

        try {
            $run($order, \App\Support\AdminOverride::make(auth()->user(), $this->emergencyReason));
            $this->emergencyReason = '';
            session()->flash('message', $success.' It is in the history with your name and reason.');
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function advance(string $toStatus, AdvanceMarketplaceOrderAction $action): void
    {
        $this->emergency(fn (MarketplaceOrder $order, $o) => $action->execute($order->id, $toStatus, null, $o), "Order marked {$toStatus}.");
    }

    /** Admin completing a `pickup` order on the store's behalf (the customer collected in person) -- no worker/OTP involved. */
    public function completePickup(CompleteMarketplaceOrderAction $action): void
    {
        $this->emergency(fn (MarketplaceOrder $order, $o) => $action->execute($order->id, null, null, null, $o), 'Order marked collected.');
    }

    /** Owner 2026-11-12: the rider cannot enter the delivery code -- an admin marks it delivered (commission, cash due as normal). */
    public function emergencyDelivered(CompleteMarketplaceOrderAction $action): void
    {
        $this->emergency(fn (MarketplaceOrder $order, $o) => $action->execute($order->id, null, null, null, $o), 'Order marked delivered.');
    }

    /** Owner 2026-11-09: admin rings one nearby rider for this order (same rules as the restaurant's Pick a rider). */
    public function ringRider(int $workerId, \App\Services\Marketplace\RiderPicker $picker): void
    {
        $order = $this->assertCanManage();

        try {
            $rider = $picker->offer($order, $workerId, auth()->user(), 'Admin');
            session()->flash('message', 'Ringing '.($rider->user?->name ?? 'the rider').'.');
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    /** Owner 2026-11-12: emergency -- give the order to a nearby rider straight away, without waiting for him to accept. */
    public function assignRiderNow(int $workerId, \App\Services\Marketplace\RiderPicker $picker, \App\Actions\AcceptMarketplaceDeliveryOfferAction $accept): void
    {
        $this->emergency(function (MarketplaceOrder $order, $o) use ($workerId, $picker, $accept) {
            $rider = $picker->offer($order, $workerId, auth()->user(), 'Admin');
            $accept->execute($order->id, $rider, $o);
        }, 'Rider assigned.');
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
            return view('livewire.marketplace-orders.manage', [
                'order' => $this->selectedOrder,
                'pickerRiders' => app(\App\Services\Marketplace\RiderPicker::class)->candidates($this->selectedOrder),
            ])->layout('layouts.admin', ['title' => 'Marketplace Orders']);
        }

        $orders = $this->scopedOrdersQuery()
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->moduleFilter === 'food', fn ($q) => $q->where('module', \App\Support\Modules::FOOD))
            ->when($this->moduleFilter === 'shop', fn ($q) => $q->where('module', '!=', \App\Support\Modules::FOOD))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($qq) => $qq
                ->where('code', 'like', "%{$this->search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$this->search}%"))))
            ->latest('id')
            ->paginate(20);

        $title = ['shop' => 'Shop Orders', 'food' => 'Food Orders'][$this->moduleFilter] ?? 'Marketplace Orders';

        return view('livewire.marketplace-orders.manage', ['order' => null, 'orders' => $orders, 'pageTitle' => $title])
            ->layout('layouts.admin', ['title' => $title]);
    }
}
