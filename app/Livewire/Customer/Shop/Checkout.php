<?php

namespace App\Livewire\Customer\Shop;

use App\Actions\CreateMarketplaceOrderAction;
use App\Models\Address;
use App\Models\Setting;
use App\Models\Store;
use App\Services\CartService;
use App\Services\Marketplace\MarketplacePaymentService;
use App\Services\ModuleActivationService;
use App\Services\WalletService;
use Livewire\Component;

/**
 * E-commerce Phase 2 — /shop/checkout/{store}: one shop's order. Delivery (a saved address in the shop's zone
 * cover) or pickup at the shop; pay online (Razorpay), from the wallet, or cash. Thumb rules shown as they apply:
 * a discounted item makes the order online-only, and free delivery above the shop's threshold is for online and
 * wallet payments only (cash always pays the delivery fee). The money is decided by CreateMarketplaceOrderAction;
 * this page only previews it with the same rules.
 */
class Checkout extends Component
{
    public Store $store;

    public string $orderType = 'delivery';

    public ?int $addressId = null;

    public string $paymentMethod = 'online';

    public string $note = '';

    public string $error = '';

    public function mount(Store $store): void
    {
        abort_unless($store->isSellable(), 404);
        abort_unless(app(ModuleActivationService::class)->isActive($store->module, array_filter([
            'zone_id' => $store->zone_id, 'franchise_id' => $store->franchise_id,
            'city_id' => $store->franchise?->city_id, 'country_id' => $store->franchise?->country_id,
        ])), 404);
        $this->store = $store;
        if (app(CartService::class)->listForStore(auth()->user(), $store->id)->isEmpty()) {
            $this->redirectRoute('customer.shop.cart', navigate: true);

            return;
        }
        $this->addressId = auth()->user()->addresses()->orderByDesc('is_default')->value('id');
        if ($this->hasDiscount()) {
            $this->paymentMethod = 'online';
        }
    }

    private function lines()
    {
        return app(CartService::class)->listForStore(auth()->user(), $this->store->id);
    }

    private function hasDiscount(): bool
    {
        return $this->lines()->contains(fn ($l) => (float) ($l->product?->discount_percent ?? 0) > 0);
    }

    public function placeOrder(CreateMarketplaceOrderAction $create, MarketplacePaymentService $payments): void
    {
        $this->error = '';
        $this->validate([
            'orderType' => ['required', 'in:delivery,pickup'],
            'paymentMethod' => ['required', 'in:online,wallet,cash'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        if ($this->orderType === 'delivery') {
            if (! $this->addressId || ! Address::where('user_id', auth()->id())->whereKey($this->addressId)->exists()) {
                $this->error = 'Choose where to deliver, or pick it up at the shop.';

                return;
            }
        }

        try {
            $order = $create->execute([
                'store_id' => $this->store->id,
                'customer_id' => auth()->id(),
                'order_type' => $this->orderType,
                'delivery_address_id' => $this->orderType === 'delivery' ? $this->addressId : null,
                'payment_method' => $this->paymentMethod,
                'special_instructions' => trim($this->note) ?: null,
            ]);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->dispatch('shop-cart-changed');

        if ($order->isAwaitingOnlinePayment()) {
            try {
                $checkout = $payments->startOnlinePayment($order);
            } catch (\RuntimeException $e) {
                // The order stays unpaid (and expires on its own); the order page offers "Pay now" again.
                session()->flash('shop_error', $e->getMessage());
                $this->redirectRoute('customer.shop.orders.show', $order, navigate: true);

                return;
            }
            $this->dispatch('razorpay-open', order: $checkout, redirect: route('customer.shop.orders.show', $order))->self();

            return;
        }

        $this->redirectRoute('customer.shop.orders.show', $order, navigate: true);
    }

    public function render()
    {
        $lines = $this->lines();
        $subtotal = (float) $lines->sum(fn ($l) => (float) ($l->variant ? $l->variant->effectivePrice() : ($l->product?->effective_price ?? 0)) * $l->quantity);
        $freeAbove = $this->store->free_delivery_above_amount;
        $freeDeliveryEarned = $freeAbove !== null && $subtotal >= (float) $freeAbove;
        $deliveryFee = $this->orderType === 'pickup' ? 0.0
            : (($this->paymentMethod !== 'cash' && $freeDeliveryEarned) ? 0.0 : (float) $this->store->delivery_fee_flat);
        $tax = round($subtotal * (float) $this->store->tax_percent / 100, 2);
        $scope = array_filter(['zone_id' => $this->store->zone_id, 'franchise_id' => $this->store->franchise_id]);

        return view('livewire.customer.shop.checkout', [
            'lines' => $lines,
            'addresses' => auth()->user()->addresses()->orderByDesc('is_default')->get(),
            'subtotal' => $subtotal,
            'deliveryFee' => $deliveryFee,
            'tax' => $tax,
            'total' => round($subtotal + $deliveryFee + $tax, 2),
            'freeDeliveryEarned' => $freeDeliveryEarned,
            'cashBlocked' => $this->hasDiscount(),
            'walletEnabled' => Setting::get('payment.wallet_enabled', null, $scope) === '1',
            'walletBalance' => app(WalletService::class)->balance(auth()->user()),
            'onlineEnabled' => Setting::get('payment.online_enabled', '1', $scope) === '1',
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.customer', ['title' => 'Checkout — '.$this->store->name]);
    }
}
