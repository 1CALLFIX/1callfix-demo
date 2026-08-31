<?php

namespace App\Livewire\Customer;

use App\Services\Customer\ServiceCartService;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The topbar services-cart icon + count badge. A tiny Livewire island so the
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
    public function refreshCount(): void
    {
        $this->count = auth()->check()
            ? app(ServiceCartService::class)->lineCount(auth()->user())
            : 0;
    }

    public function render()
    {
        return view('livewire.customer.cart-count');
    }
}
