<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceOrder;
use App\Models\Setting;

/**
 * E-commerce Phase 5 — the tax invoice for a completed shop order, issued by the seller (the supplier) with
 * 1CallFix as the e-commerce operator. Printable HTML (the browser saves it as PDF). The customer, the shop's
 * seller, or an admin with marketplace_orders.view may open it; nobody else.
 */
class ShopInvoiceController extends Controller
{
    public function show(MarketplaceOrder $order)
    {
        $user = auth()->user();
        $order->load(['items', 'store.franchise', 'customer', 'deliveryAddress']);
        $isCustomer = $order->customer_id === $user->id;
        $isSeller = $order->store?->provider?->user_id === $user->id;
        $isAdmin = $user->hasPermission('marketplace_orders.view', array_filter([
            'zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id,
            'city_id' => $order->store?->franchise?->city_id, 'country_id' => $order->store?->franchise?->country_id,
        ]));
        abort_unless($isCustomer || $isSeller || $isAdmin, 404);
        abort_unless($order->status === 'completed', 404);

        return view('shop.invoice', [
            'order' => $order,
            'store' => $order->store,
            'platformName' => Setting::get('branding.platform_name', '1CallFix'),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
            'grievance' => array_values(array_filter(preg_split('/\R/', (string) \App\Support\MarketplaceSettings::get('marketplace.grievance_officer'))))
        ]);
    }
}
