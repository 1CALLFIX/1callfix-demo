<?php

namespace App\Livewire\Customer\Shop;

use App\Models\CartItem;
use App\Models\Setting;
use Livewire\Attributes\On;
use Livewire\Component;

/** E-commerce Phase 2 — the floating "View bag" bar on shop pages: item count and running total, one tap to the bag. */
class CartBar extends Component
{
    #[On('shop-cart-changed')]
    public function refresh(): void
    {
        // re-render
    }

    public function render()
    {
        $lines = auth()->check() ? CartItem::where('user_id', auth()->id())->get(['quantity', 'unit_price_snapshot']) : collect();

        return view('livewire.customer.shop.cart-bar', [
            'count' => (int) $lines->sum('quantity'),
            'total' => (float) $lines->sum(fn ($l) => (float) $l->unit_price_snapshot * $l->quantity),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ]);
    }
}
