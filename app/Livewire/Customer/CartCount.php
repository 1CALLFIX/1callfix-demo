<?php

namespace App\Livewire\Customer;

use App\Services\Customer\ServiceCartService;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The topbar cart icon + count badge: services lines plus shop items (one cart, owner 2026-10-30). A tiny Livewire island so the
 * count refreshes on the `cart-updated` event any add/remove dispatches,
 * without reloading the page. Rendered only for a signed-in customer (the
 * cart is per-user, DB-backed).
 */
class CartCount extends Component
{
    public int $count = 0;

    public function mount(): void
    {
        $this->refreshCount();
    }

    #[On('cart-updated')]
    #[On('shop-cart-changed')]
    public function refreshCount(): void
    {
        $this->count = auth()->check()
            ? app(ServiceCartService::class)->lineCount(auth()->user()) + (int) \App\Models\CartItem::where('user_id', auth()->id())->sum('quantity')
            : 0;
    }

    public function render()
    {
        return view('livewire.customer.cart-count');
    }
}
