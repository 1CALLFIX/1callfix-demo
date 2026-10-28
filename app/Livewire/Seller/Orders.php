<?php

namespace App\Livewire\Seller;

use App\Actions\AdminCancelMarketplaceOrderAction;
use App\Actions\AdvanceMarketplaceOrderAction;
use App\Actions\CompleteMarketplaceOrderAction;
use App\Livewire\Seller\Concerns\InteractsWithSeller;
use App\Models\MarketplaceOrder;
use App\Models\Setting;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Seller → Orders: new orders to accept, then preparing → ready, and the hand-over at the shop for a pickup order
 * (the customer's collection code). A ready delivery order goes to riders on its own (MarketplaceDispatchJob).
 * Every step runs the same Actions as the admin screen; the seller can reject an order before it is ready, which
 * refunds the customer in full with no fee.
 */
class Orders extends Component
{
    use InteractsWithSeller;
    use WithPagination;

    #[Url]
    public string $tab = 'new'; // new|active|done|cancelled

    public ?int $rejectingId = null;

    public string $rejectReason = '';

    /** Collection codes typed per pickup order, keyed by order id. */
    public array $codes = [];

    public string $flashMessage = '';

    public string $flashType = 'success';

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function advance(int $orderId, string $to, AdvanceMarketplaceOrderAction $action): void
    {
        $this->ownOrder($orderId);
        $this->run(fn () => $action->execute($orderId, $to), match ($to) {
            'accepted' => 'Order accepted.',
            'preparing' => 'Marked as preparing.',
            'ready' => 'Marked ready.',
            default => 'Updated.',
        });
    }

    public function startReject(int $orderId): void
    {
        $this->ownOrder($orderId);
        $this->rejectingId = $orderId;
        $this->rejectReason = '';
    }

    public function reject(AdminCancelMarketplaceOrderAction $action): void
    {
        $this->validate(['rejectReason' => ['required', 'string', 'min:3', 'max:300']], ['rejectReason.required' => 'Tell the customer why.']);
        $order = $this->ownOrder((int) $this->rejectingId);
        $this->run(fn () => $action->execute($order->id, trim($this->rejectReason), 'seller'), 'Order cancelled. The customer is refunded in full.');
        $this->rejectingId = null;
    }

    public function handOver(int $orderId, CompleteMarketplaceOrderAction $action): void
    {
        $order = $this->ownOrder($orderId);
        if ($order->order_type !== 'pickup') {
            $this->flash('A delivery order is completed by the rider.', 'error');

            return;
        }
        $this->run(fn () => $action->execute($orderId, null, (string) ($this->codes[$orderId] ?? ''), auth()->user()), 'Handed over. Order complete.');
    }

    private function run(callable $step, string $success): void
    {
        try {
            $step();
            $this->flash($success, 'success');
        } catch (\RuntimeException $e) {
            $this->flash($e->getMessage(), 'error');
        }
    }

    private function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }

    public function render()
    {
        $store = $this->shop();
        $statuses = match ($this->tab) {
            'active' => ['accepted', 'preparing', 'ready'],
            'done' => ['completed'],
            'cancelled' => ['cancelled'],
            default => ['pending'],
        };

        $base = MarketplaceOrder::where('store_id', $store->id);

        return view('livewire.seller.orders', [
            'orders' => (clone $base)->whereIn('status', $statuses)
                ->with(['items', 'customer:id,name', 'deliveryAddress', 'assignedWorker.user:id,name', 'franchise'])
                ->orderBy($this->tab === 'new' || $this->tab === 'active' ? 'created_at' : 'updated_at', $this->tab === 'new' || $this->tab === 'active' ? 'asc' : 'desc')
                ->paginate(20),
            'counts' => [
                'new' => (clone $base)->where('status', 'pending')->count(),
                'active' => (clone $base)->whereIn('status', ['accepted', 'preparing', 'ready'])->count(),
            ],
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.seller', ['title' => 'Orders']);
    }
}
