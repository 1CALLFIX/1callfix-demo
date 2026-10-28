<?php

namespace App\Livewire\Customer\Shop;

use App\Models\CartItem;
use App\Models\Setting;
use App\Models\Store;
use App\Services\CartService;
use Livewire\Component;

/**
 * E-commerce Phase 2 — /shop/bag: everything in the customer's shop bag, one block per shop (each shop is its own
 * order: its own rider, its own minimum and delivery fee). Lines whose product went off sale are flagged, never
 * silently ordered.
 */
class Bag extends Component
{
    public string $message = '';

    private function ownLine(int $lineId): CartItem
    {
        $line = CartItem::where('user_id', auth()->id())->with('product')->find($lineId);
        abort_unless($line, 404);

        return $line;
    }

    public function change(int $lineId, int $step, CartService $cart): void
    {
        $line = $this->ownLine($lineId);
        $wanted = $line->quantity + ($step > 0 ? 1 : -1);
        $limit = min((int) ($line->variant?->stock ?? $line->product?->stock ?? 0), (int) ($line->product?->maximum_cart_quantity ?: PHP_INT_MAX));

        if ($wanted <= 0) {
            $cart->remove($line);
        } elseif ($step > 0 && $wanted > $limit) {
            $this->message = 'Only '.max(0, $limit).' of '.$line->product?->name.' can be ordered.';

            return;
        } else {
            $cart->updateQuantity($line, $wanted);
        }
        $this->message = '';
        $this->dispatch('shop-cart-changed');
    }

    public function removeLine(int $lineId, CartService $cart): void
    {
        $cart->remove($this->ownLine($lineId));
        $this->dispatch('shop-cart-changed');
    }

    public function render()
    {
        $lines = CartItem::where('user_id', auth()->id())->with(['product', 'variant'])->get();
        $stores = Store::with('franchise.city')->whereIn('id', $lines->pluck('store_id')->unique())->get()->keyBy('id');

        $groups = $lines->groupBy('store_id')->map(function ($storeLines, $storeId) use ($stores) {
            $store = $stores->get($storeId);
            $available = $storeLines->filter(fn ($l) => $store?->isSellable() && $l->product && $l->product->is_active && $l->product->is_approved);
            $subtotal = (float) $available->sum(fn ($l) => (float) ($l->variant ? $l->variant->effectivePrice() : $l->product->effective_price) * $l->quantity);

            return [
                'store' => $store,
                'lines' => $storeLines,
                'subtotal' => $subtotal,
                'short' => $store ? max(0, (float) $store->minimum_order_amount - $subtotal) : 0,
                'canCheckout' => $store?->isSellable() && $store->is_open && $available->isNotEmpty(),
            ];
        })->filter(fn ($g) => $g['store'] !== null);

        return view('livewire.customer.shop.bag', [
            'groups' => $groups,
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.customer', ['title' => 'Your bag']);
    }
}
