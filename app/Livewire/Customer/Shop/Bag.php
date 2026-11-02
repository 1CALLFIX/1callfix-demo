<?php

namespace App\Livewire\Customer\Shop;

use App\Models\CartItem;
use App\Models\Setting;
use App\Models\Store;
use App\Services\CartService;
use App\Services\GuestShopCart;
use App\Support\MarketplaceSettings;
use Livewire\Component;

/**
 * E-commerce Phase 2 — the shop cart: everything in the customer's shop cart, one block per shop (each shop is its own
 * order: its own rider, its own minimum and delivery fee). Lines whose product went off sale are flagged, never
 * silently ordered.
 *
 * One cart (owner 2026-10-30): drawn inside /cart under the services ($embedded); /shop/bag now redirects there.
 * Shop Phase A 2026-11-02: a visitor's cart (GuestShopCart, session) is drawn the same way; "Checkout" asks them to
 * sign in and comes back to that shop's checkout with the cart joined. Each shop block shows what paying online
 * saves (offers + free delivery progress) and the delivery promise.
 */
class Bag extends Component
{
    public bool $embedded = false;

    public string $message = '';

    private function ownLine(int $lineId): CartItem
    {
        $line = CartItem::where('user_id', auth()->id())->with('product')->find($lineId);
        abort_unless($line, 404);

        return $line;
    }

    public function change(int $lineId, int $step, CartService $cart): void
    {
        if (! auth()->check()) {
            $this->changeGuest($lineId, $step);

            return;
        }
        $line = $this->ownLine($lineId);
        $wanted = $line->quantity + ($step > 0 ? 1 : -1);
        if ($step > 0 && $wanted > $this->limitFor($line)) {
            $this->message = \App\Support\SiteText::get('cart.only_left', ['count' => max(0, $this->limitFor($line)), 'item' => (string) $line->product?->name]);

            return;
        }
        $wanted <= 0 ? $cart->remove($line) : $cart->updateQuantity($line, $wanted);
        $this->message = '';
        $this->dispatch('shop-cart-changed');
    }

    public function removeLine(int $lineId, CartService $cart): void
    {
        if (! auth()->check()) {
            app(GuestShopCart::class)->setByKey($lineId, 0);
        } else {
            $cart->remove($this->ownLine($lineId));
        }
        $this->dispatch('shop-cart-changed');
    }

    /** A shop's checkout; a visitor signs in first and lands back on it, cart joined (GuestShopCart::mergeInto). */
    public function checkout(int $storeId): void
    {
        $url = route('customer.shop.checkout', $storeId);
        if (! auth()->check()) {
            session()->put('url.intended', $url);
            $this->redirectRoute('customer.login', navigate: true);

            return;
        }
        $this->redirect($url, navigate: true);
    }

    private function changeGuest(int $key, int $step): void
    {
        $guest = app(GuestShopCart::class);
        $line = $guest->lines()->firstWhere('id', $key);
        if (! $line) {
            return;
        }
        $wanted = $line->quantity + ($step > 0 ? 1 : -1);
        if ($step > 0 && $wanted > $this->limitFor($line)) {
            $this->message = \App\Support\SiteText::get('cart.only_left', ['count' => max(0, $this->limitFor($line)), 'item' => (string) $line->product?->name]);

            return;
        }
        $guest->setByKey($key, max(0, $wanted));
        $this->message = '';
        $this->dispatch('shop-cart-changed');
    }

    private function limitFor(CartItem $line): int
    {
        return min((int) ($line->variant?->stock ?? $line->product?->stock ?? 0), (int) ($line->product?->maximum_cart_quantity ?: PHP_INT_MAX));
    }

    public function render()
    {
        $guest = ! auth()->check();
        $lines = $guest ? app(GuestShopCart::class)->lines() : CartItem::where('user_id', auth()->id())->with(['product', 'variant'])->get();
        $stores = Store::with('franchise.city')->whereIn('id', $lines->pluck('store_id')->unique())->get()->keyBy('id');

        $groups = $lines->groupBy('store_id')->map(function ($storeLines, $storeId) use ($stores) {
            $store = $stores->get($storeId);
            $available = $storeLines->filter(fn ($l) => $store?->isSellable() && $l->product && $l->product->is_active && $l->product->is_approved);
            $subtotal = (float) $available->sum(fn ($l) => $l->unitPrice() * $l->quantity);
            $listTotal = (float) $available->sum(fn ($l) => ($l->listUnitPrice() ?? $l->unitPrice()) * $l->quantity);

            return [
                'store' => $store,
                'lines' => $storeLines,
                'count' => (int) $available->sum('quantity'),
                'subtotal' => $subtotal,
                'listTotal' => $listTotal,
                // What the offers save (online / wallet only — cash pays the full price).
                'saving' => max(0, round($listTotal - $subtotal, 2)),
                'short' => $store ? max(0, (float) $store->minimum_order_amount - $subtotal) : 0,
                // Free delivery is a benefit (online payment only): shown here as progress, decided at checkout.
                'freeAbove' => $store && $store->free_delivery_above_amount !== null && (float) $store->free_delivery_above_amount > 0 && (float) $store->delivery_fee_flat > 0
                    ? (float) $store->free_delivery_above_amount : null,
                'promise' => $store ? MarketplaceSettings::deliveryPromise($store) : null,
                'canCheckout' => $store?->isSellable() && $store->is_open && $available->isNotEmpty(),
            ];
        })->filter(fn ($g) => $g['store'] !== null);

        return view('livewire.customer.shop.bag', [
            'groups' => $groups,
            'guest' => $guest,
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.customer', ['title' => \App\Support\SiteText::get('cart.title')]);
    }
}
