<?php

namespace App\Livewire\Seller;

use App\Livewire\Seller\Concerns\InteractsWithSeller;
use App\Models\MarketplaceOrder;
use App\Models\Product;
use App\Models\Setting;
use Livewire\Component;

/**
 * Seller home: where the shop stands (approval, documents, Commerce switched on here), the open/closed switch, and
 * today's numbers. Opening needs an approved, active shop; customers can only buy when Commerce is on for its area.
 */
class Dashboard extends Component
{
    use InteractsWithSeller;

    public string $notice = '';

    public function toggleOpen(): void
    {
        $store = $this->shop();
        if (! $store->isSellable()) {
            $this->notice = 'Your shop can open once it is approved.';

            return;
        }
        $store->update(['is_open' => ! $store->is_open]);
        $this->notice = $store->is_open ? 'Your shop is open for orders.' : 'Your shop is closed. Customers cannot order until you open it.';
    }

    public function render()
    {
        $store = $this->shop()->load('franchise');
        $seller = $this->seller();
        $today = MarketplaceOrder::where('store_id', $store->id)->whereDate('created_at', today());

        return view('livewire.seller.dashboard', [
            'store' => $store,
            'kycStatus' => $seller->kyc_status,
            'moduleLive' => $this->shopModuleLive($store),
            'ordersToday' => (clone $today)->count(),
            'salesToday' => (float) (clone $today)->where('status', 'completed')->sum('subtotal'),
            'activeOrders' => MarketplaceOrder::where('store_id', $store->id)->whereIn('status', ['pending', 'accepted', 'preparing', 'ready'])->count(),
            'productCount' => Product::where('store_id', $store->id)->count(),
            'awaitingApproval' => Product::where('store_id', $store->id)->where('is_approved', false)->count(),
            'lowStock' => Product::where('store_id', $store->id)->where('is_active', true)->where('stock', '<=', 3)->orderBy('stock')->limit(5)->get(['id', 'name', 'stock']),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.seller', ['title' => 'Home']);
    }
}
