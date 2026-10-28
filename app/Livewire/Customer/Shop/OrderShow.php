<?php

namespace App\Livewire\Customer\Shop;

use App\Actions\AdminCancelMarketplaceOrderAction;
use App\Models\MarketplaceOrder;
use App\Models\Review;
use App\Models\Setting;
use App\Services\Marketplace\MarketplacePaymentService;
use Livewire\Component;

/**
 * E-commerce Phase 2 — /shop/orders/{order}: live status (polls while open), the code to give the rider or the shop,
 * "Pay now" for an unpaid online order, cancel until packing starts, and a rating for the shop once it is done.
 * The customer's own orders only.
 */
class OrderShow extends Component
{
    use \Livewire\WithFileUploads;

    public MarketplaceOrder $order;

    public bool $confirmingCancel = false;

    public string $cancelReason = '';

    public int $rating = 0;

    public string $comment = '';

    public string $flash = '';

    public string $flashType = 'success';

    public function mount(MarketplaceOrder $order): void
    {
        abort_unless($order->customer_id === auth()->id(), 404);
        $this->order = $order;
        if (session('shop_error')) {
            $this->flash = (string) session('shop_error');
            $this->flashType = 'error';
        }
    }

    public function payNow(MarketplacePaymentService $payments): void
    {
        $this->order->refresh();
        try {
            $checkout = $payments->startOnlinePayment($this->order);
        } catch (\RuntimeException $e) {
            $this->flash = $e->getMessage();
            $this->flashType = 'error';

            return;
        }
        $this->dispatch('razorpay-open', order: $checkout, redirect: route('customer.shop.orders.show', $this->order))->self();
    }

    public function cancel(AdminCancelMarketplaceOrderAction $action): void
    {
        $this->validate(['cancelReason' => ['required', 'string', 'min:3', 'max:300']], ['cancelReason.required' => 'Tell us why, so the shop knows.']);
        try {
            $this->order = $action->execute($this->order->id, trim($this->cancelReason), 'customer');
            $this->flash = (float) $this->order->cancellation_fee > 0
                ? 'Order cancelled. A cancellation fee applies; the rest is refunded.'
                : 'Order cancelled.'.($this->order->payment_status !== 'pending' ? ' Your money is refunded in full.' : '');
            $this->flashType = 'success';
        } catch (\RuntimeException $e) {
            $this->flash = $e->getMessage();
            $this->flashType = 'error';
        }
        $this->confirmingCancel = false;
    }

    // ── E-commerce Phase 3b: returns (damaged / wrong item, within the return window) ──

    public bool $returning = false;

    public string $returnReason = 'damaged';

    public string $returnDescription = '';

    /** order item id => quantity to return */
    public array $returnQty = [];

    public array $returnPhotos = [];

    public function requestReturn(\App\Services\Marketplace\MarketplaceReturnService $returns): void
    {
        $this->validate([
            'returnReason' => ['required', 'in:'.implode(',', array_keys(\App\Models\MarketplaceReturn::REASONS))],
            'returnDescription' => ['required', 'string', 'min:10', 'max:1000'],
            'returnQty' => ['array'],
            'returnQty.*' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'returnPhotos' => ['required', 'array', 'min:1', 'max:3'],
            'returnPhotos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'returnDescription.required' => 'Tell us what is wrong.',
            'returnPhotos.required' => 'Add at least one photo of the item.',
        ]);

        $this->order->refresh()->load('items');
        if (($why = $returns->cannotRequest($this->order, auth()->id())) !== null) {
            $this->flash = $why;
            $this->flashType = 'error';

            return;
        }
        $paths = array_map(fn ($file) => $file->store('returns/'.$this->order->id, 'public'), $this->returnPhotos);
        try {
            $returns->request($this->order, auth()->user(), $this->returnReason, $this->returnDescription, array_map('intval', $this->returnQty), $paths);
        } catch (\RuntimeException $e) {
            foreach ($paths as $path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($path);
            }
            $this->flash = $e->getMessage();
            $this->flashType = 'error';

            return;
        }
        $this->reset('returning', 'returnDescription', 'returnQty', 'returnPhotos');
        $this->flash = 'Return requested. We will look at it and tell you what happens next.';
        $this->flashType = 'success';
    }

    public function rate(): void
    {
        $this->order->refresh();
        abort_unless($this->order->status === 'completed', 403);
        $this->validate(['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:1000']], ['rating.between' => 'Tap a star.']);
        if (Review::where('marketplace_order_id', $this->order->id)->exists()) {
            return;
        }
        Review::create([
            'marketplace_order_id' => $this->order->id,
            'customer_id' => auth()->id(),
            'provider_id' => $this->order->store->provider_id,
            'rating' => $this->rating,
            'comment' => trim($this->comment) ?: null,
        ]);
        $this->flash = 'Thanks for rating '.$this->order->store->name.'.';
        $this->flashType = 'success';
    }

    public function render()
    {
        $this->order->load(['items', 'store', 'deliveryAddress', 'assignedWorker.user:id,name,phone', 'statusHistory']);
        $open = in_array($this->order->status, ['pending', 'accepted', 'preparing', 'ready'], true);
        $showCode = $this->order->status === 'ready'
            && ($this->order->order_type === 'pickup' || $this->order->assigned_worker_id !== null);

        return view('livewire.customer.shop.order-show', [
            'open' => $open,
            'showCode' => $showCode,
            'canCancel' => in_array($this->order->status, ['pending', 'accepted'], true),
            'review' => Review::where('marketplace_order_id', $this->order->id)->first(),
            'returnDays' => (int) \App\Support\MarketplaceSettings::get('marketplace.return_window_days'),
            'returnRow' => \App\Models\MarketplaceReturn::where('marketplace_order_id', $this->order->id)->latest('id')->first(),
            'canReturn' => app(\App\Services\Marketplace\MarketplaceReturnService::class)->cannotRequest($this->order, auth()->id()) === null,
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.customer', ['title' => 'Order '.$this->order->code]);
    }
}
