<?php

namespace App\Livewire\Customer\Shop;

use App\Models\MarketplaceOrder;
use App\Models\Setting;
use Livewire\Component;
use Livewire\WithPagination;

/** E-commerce Phase 2 — /shop/orders: the customer's shop orders, newest first. */
class Orders extends Component
{
    use WithPagination;

    public function render()
    {
        return view('livewire.customer.shop.orders', [
            'orders' => MarketplaceOrder::where('customer_id', auth()->id())->with(['store:id,name,logo', 'items:id,marketplace_order_id,quantity', 'franchise'])
                ->latest('id')->paginate(15),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.customer', ['title' => 'Shop orders']);
    }
}
