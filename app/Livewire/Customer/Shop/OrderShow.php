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

    /** Set by the Razorpay success handler (?paid=1): wait for the bank's confirmation, never offer Pay again. */
    #[\Livewire\Attributes\Url(as: 'paid')]
    public bool $justPaid = false;

    public string $flashType = 'success';

    public function mount(MarketplaceOrder $order): void
    {
        abort_unless($order->customer_id === auth()->id(), 404);
        $this->order = $order;
        if ($order->isFood()) {
            $this->returnReason = 'missing'; // Food F4: problem reasons, not shop return reasons
        }
        if (session('shop_error')) {
            $this->flash = (string) session('shop_error');
            $this->flashType = 'error';
        }
    }

    /**
     * NO PAYMENT, NO BOOKING (owner 2026-11-07): "open" while an online order waits for its payment, "ended" once it
     * ran out of time or was replaced, null for a real order. The page then shows only the payment step.
     */
    private function attemptState(): ?string
    {
        $o = $this->order;
        if ($o->payment_method !== 'online' || $o->payment_status !== 'pending') {
            return null;
        }

        return match ($o->status) {
            'pending' => 'open',
            'cancelled' => 'ended',
            default => null,
        };
    }

    /** Back to the checkout to pay another way; the cart was kept. Ordering again replaces this unpaid order. */
    public function changePayment()
    {
        return $this->redirectRoute('customer.shop.checkout', ['store' => $this->order->store_id], navigate: true);
    }

    /** "Don't order now": the unpaid order ends quietly (stock and coupon back); the cart stays. */
    public function leaveAttempt(AdminCancelMarketplaceOrderAction $action)
    {
        $this->order->refresh();
        if ($this->attemptState() === 'open') {
            try {
                $action->execute($this->order->id, 'Customer left the payment — no order was made', 'customer', notifyCustomer: false);
            } catch (\RuntimeException $e) {
                report($e);
            }
        }

        return $this->redirectRoute('customer.cart', navigate: true);
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
        $this->validate(['cancelReason' => ['required', 'string', 'min:3', 'max:300']], ['cancelReason.required' => \App\Support\SiteText::get('shop_order.msg.cancel_why')]);
        try {
            $this->order = $action->execute($this->order->id, trim($this->cancelReason), 'customer');
            $this->flash = (float) $this->order->cancellation_fee > 0
                ? \App\Support\SiteText::get('shop_order.msg.cancelled_fee')
                : \App\Support\SiteText::get('shop_order.msg.cancelled').($this->order->payment_status !== 'pending' ? \App\Support\SiteText::get('shop_order.msg.refunded_full') : '');
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

    /** Food problem rules 2026-11-03: an online food order's refund goes to the wallet unless the customer ticks this. */
    public bool $refundToBank = false;

    public string $returnDescription = '';

    /** order item id => quantity to return */
    public array $returnQty = [];

    public array $returnPhotos = [];

    public function requestReturn(\App\Services\Marketplace\MarketplaceReturnService $returns): void
    {
        // Food F4: the same form reports a problem with a food order; the photo is a Food Settings switch.
        $food = $this->order->isFood();
        $photoNeeded = ! $food || $returns->foodPhotoRequired($this->order);
        $this->validate([
            'returnReason' => ['required', 'in:'.implode(',', array_keys($food ? \App\Models\MarketplaceReturn::FOOD_REASONS : \App\Models\MarketplaceReturn::REASONS))],
            'returnDescription' => ['required', 'string', 'min:10', 'max:1000'],
            'returnQty' => ['array'],
            'returnQty.*' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'returnPhotos' => $photoNeeded ? ['required', 'array', 'min:1', 'max:3'] : ['nullable', 'array', 'max:3'],
            'returnPhotos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'returnDescription.required' => \App\Support\SiteText::get($food ? 'food.issue.what_wrong_needed' : 'shop_order.msg.return_what'),
            'returnDescription.min' => \App\Support\SiteText::get($food ? 'food.issue.what_wrong_needed' : 'shop_order.msg.return_what'),
            'returnPhotos.required' => \App\Support\SiteText::get($food ? 'food.issue.photo_needed' : 'shop_order.msg.return_photo'),
        ]);

        $this->order->refresh()->load('items');
        if (($why = $returns->cannotRequest($this->order, auth()->id())) !== null) {
            $this->flash = $why;
            $this->flashType = 'error';

            return;
        }
        $paths = array_map(fn ($file) => $file->store('returns/'.$this->order->id, 'public'), $this->returnPhotos);
        try {
            $returns->request($this->order, auth()->user(), $this->returnReason, $this->returnDescription, array_map('intval', $this->returnQty), $paths, $food && $this->refundToBank);
        } catch (\RuntimeException $e) {
            foreach ($paths as $path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($path);
            }
            $this->flash = $e->getMessage();
            $this->flashType = 'error';

            return;
        }
        $this->reset('returning', 'returnDescription', 'returnQty', 'returnPhotos');
        $this->flash = \App\Support\SiteText::get($food ? 'food.issue.sent' : 'shop_order.msg.return_sent');
        $this->flashType = 'success';
    }

    public function rate(): void
    {
        $this->order->refresh();
        abort_unless($this->order->status === 'completed', 403);
        $this->validate(['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:1000']], ['rating.between' => \App\Support\SiteText::get('shop_order.msg.tap_star')]);
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
        $this->flash = \App\Support\SiteText::get('shop_order.msg.thanks_rating', ['shop' => $this->order->store->name]);
        $this->flashType = 'success';
    }

    /** Food F3: one plain line on where a food order is (accept clock, promised ready time, rider). */
    private function foodStatus(): ?string
    {
        $o = $this->order;
        if (! $o->isFood() || $o->isAwaitingOnlinePayment()) {
            return null;
        }
        $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r);
        $time = fn ($at) => app(\App\Services\TimezoneResolver::class)->format($at, $o->franchise, 'g:i A');

        return match (true) {
            $o->status === 'pending' && $o->accept_by !== null => $t('food.order.waiting', ['time' => $time($o->accept_by)]),
            $o->status === 'accepted' && $o->ready_by !== null => $t('food.order.accepted', ['time' => $o->readyByLocal()]),
            $o->status === 'preparing' && $o->ready_by !== null => $t('food.order.cooking', ['time' => $o->readyByLocal()])
                .($o->order_type === 'delivery' && $o->assigned_worker_id ? ' '.$t('food.order.rider_on_way') : ''),
            $o->status === 'ready' => $t('food.order.ready'),
            default => null,
        };
    }

    public function render()
    {
        $this->order->load(['items', 'store', 'deliveryAddress', 'assignedWorker.user:id,name,phone', 'statusHistory']);
        $open = in_array($this->order->status, ['pending', 'accepted', 'preparing', 'ready'], true);
        $showCode = $this->order->status === 'ready'
            && ($this->order->order_type === 'pickup' || $this->order->assigned_worker_id !== null);

        return view('livewire.customer.shop.order-show', [
            'attempt' => $this->attemptState(),
            'open' => $open,
            'showCode' => $showCode,
            // Food F2: a food order can be cancelled only until the restaurant accepts (the action enforces it too).
            'canCancel' => $this->order->isFood() ? $this->order->status === 'pending' : in_array($this->order->status, ['pending', 'accepted'], true),
            'review' => Review::where('marketplace_order_id', $this->order->id)->first(),
            'returnDays' => (int) \App\Support\MarketplaceSettings::get('marketplace.return_window_days'),
            'returnRow' => \App\Models\MarketplaceReturn::where('marketplace_order_id', $this->order->id)->latest('id')->first(),
            // Food F4: a food order has no return; the same section reports a problem within the Food Settings window.
            'canReturn' => app(\App\Services\Marketplace\MarketplaceReturnService::class)->cannotRequest($this->order, auth()->id()) === null,
            'foodIssueMinutes' => $this->order->isFood() ? app(\App\Services\Marketplace\MarketplaceReturnService::class)->foodWindowMinutes($this->order) : null,
            'foodPhotoNeeded' => $this->order->isFood() && app(\App\Services\Marketplace\MarketplaceReturnService::class)->foodPhotoRequired($this->order),
            'foodPaused' => $this->order->isFood() && $this->order->customer_id === auth()->id()
                && app(\App\Services\Marketplace\MarketplaceReturnService::class)->foodReportingPaused($this->order),
            'foodStatus' => $this->foodStatus(),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.customer', ['title' => 'Order '.$this->order->code]);
    }
}
