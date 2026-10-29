<?php

namespace App\Livewire\Seller;

use App\Models\MarketplaceOrder;
use App\Models\Store;
use Livewire\Component;

/**
 * The new-order bell on every seller page: polls the shop's orders that need the seller (a cash order, or an online
 * order already paid, still at `pending`) and rings when the count goes up. Unpaid online orders are left out — the
 * seller cannot accept them yet (Phase 0).
 */
class OrderWatcher extends Component
{
    public int $waiting = 0;

    public function mount(): void
    {
        $this->waiting = $this->count();
    }

    private function count(): int
    {
        $storeIds = Store::whereHas('provider', fn ($q) => $q->where('user_id', auth()->id()))->pluck('id');

        return MarketplaceOrder::whereIn('store_id', $storeIds)->where('status', 'pending')
            ->where(fn ($q) => $q->where('payment_method', 'cash')->orWhere('payment_status', 'paid'))
            ->count();
    }

    public function refreshCount(): void
    {
        $before = $this->waiting;
        $this->waiting = $this->count();
        if ($this->waiting > $before) {
            $this->dispatch('seller-new-order', count: $this->waiting);
        }
    }

    public function render()
    {
        return view('livewire.seller.order-watcher');
    }
}
