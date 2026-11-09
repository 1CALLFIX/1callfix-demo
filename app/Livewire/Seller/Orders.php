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

    /** Food F2: cooking time the restaurant confirms when accepting, keyed by order id (blank = its usual time). */
    public array $prepTimes = [];

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
        $order = $this->ownOrder($orderId);
        $food = $order->isFood();
        // Food F2: the restaurant confirms its cooking time when it accepts.
        $prep = null;
        if ($food && $to === 'accepted' && trim((string) ($this->prepTimes[$orderId] ?? '')) !== '') {
            $prep = filter_var($this->prepTimes[$orderId], FILTER_VALIDATE_INT);
            if ($prep === false) {
                $this->flash('Enter the cooking time in whole minutes.', 'error');

                return;
            }
        }
        $this->run(fn () => $action->execute($orderId, $to, $prep), match ($to) {
            'accepted' => $food ? 'Order accepted. Start cooking.' : 'Order accepted.',
            'preparing' => $food ? 'Cooking.' : 'Marked as preparing.',
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

    /** E-commerce Phase 3b: the seller confirms a returned item is back at the shop (own shop's returns only). */
    public function receiveReturn(int $returnId, \App\Services\Marketplace\MarketplaceReturnService $returns): void
    {
        $row = \App\Models\MarketplaceReturn::where('store_id', $this->shop()->id)->find($returnId);
        abort_unless($row, 404);
        $result = $returns->markReceived($row, auth()->user());
        $this->flash($result['message'], $result['ok'] ? 'success' : 'error');
    }

    /** Food problem rules 2026-11-03: dispute reasons typed on the Problems tab, by problem id. */
    public array $disputeNotes = [];

    public function answerProblem(int $returnId, string $answer, \App\Services\Marketplace\MarketplaceReturnService $returns): void
    {
        $row = \App\Models\MarketplaceReturn::where('store_id', $this->shop()->id)->find($returnId);
        abort_unless($row, 404);
        $result = $returns->respondAsRestaurant($row, auth()->user(), $answer === 'agree', (string) ($this->disputeNotes[$returnId] ?? ''));
        $this->flash($result['message'], $result['ok'] ? 'success' : 'error');
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

    /** Owner 2026-11-09: the order whose "Pick a rider" list is open (one at a time). */
    public ?int $pickingFor = null;

    public function togglePicker(int $orderId): void
    {
        $this->ownOrder($orderId);
        $this->pickingFor = $this->pickingFor === $orderId ? null : $orderId;
    }

    /** Rings one nearby 1CallFix rider for this order; he accepts on his phone as usual. */
    public function ringRider(int $orderId, int $workerId, \App\Services\Marketplace\RiderPicker $picker): void
    {
        $order = $this->ownOrder($orderId);
        try {
            $rider = $picker->offer($order, $workerId, auth()->user(), $order->isFood() ? 'Restaurant' : 'Shop');
            $this->flash('Ringing '.($rider->user?->name ?? 'the rider').'. If nobody accepts within a minute, ring another rider.', 'success');
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
            'returns' => ['__none__'],
            default => ['pending'],
        };

        // booked(): a shop or restaurant never sees an unpaid online order (NO PAYMENT, NO BOOKING, owner 2026-11-07).
        $base = MarketplaceOrder::where('store_id', $store->id)->booked();

        return view('livewire.seller.orders', [
            'orders' => (clone $base)->whereIn('status', $statuses)
                ->with(['items', 'customer:id,name', 'deliveryAddress', 'assignedWorker.user:id,name', 'franchise'])
                ->orderBy($this->tab === 'new' || $this->tab === 'active' ? 'created_at' : 'updated_at', $this->tab === 'new' || $this->tab === 'active' ? 'asc' : 'desc')
                ->paginate(20),
            'counts' => [
                'new' => (clone $base)->where('status', 'pending')->count(),
                'active' => (clone $base)->whereIn('status', ['accepted', 'preparing', 'ready'])->count(),
                'returns' => \App\Models\MarketplaceReturn::where('store_id', $store->id)->whereIn('status', ['requested', 'accepted'])->count(),
            ],
            'returns' => $this->tab === 'returns'
                ? \App\Models\MarketplaceReturn::where('store_id', $store->id)->with('order:id,code')->latest('id')->limit(50)->get()
                : collect(),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
            // Food F2: the cooking time pre-filled on Accept (its usual time plus busy minutes).
            'usualPrep' => $store->isRestaurant() ? $store->currentPrepMinutes() : null,
            'isRestaurant' => $store->isRestaurant(),
            // Owner 2026-11-09: nearby riders for the open "Pick a rider" list.
            'pickerRiders' => $this->pickingFor ? app(\App\Services\Marketplace\RiderPicker::class)->candidates($this->ownOrder($this->pickingFor)) : collect(),
        ])->layout('components.layouts.seller', ['title' => 'Orders']);
    }
}
