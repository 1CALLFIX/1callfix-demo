<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceOrder;

/**
 * Restaurants with no billing software (owner 2026-11-12): the kitchen slip (KOT) for one order — order number, time,
 * delivery or pickup, each item with its quantity, choice and add-ons, and the customer's note. Narrow, large print,
 * sized for a 58 / 80 mm receipt printer but fine on any printer or phone. Only the shop's own owner can open it.
 */
class KitchenSlipController extends Controller
{
    public function show(MarketplaceOrder $order)
    {
        $order->load(['items', 'store.provider', 'franchise']);
        abort_unless($order->store?->provider?->user_id === auth()->id(), 404);

        return view('seller.kitchen-slip', [
            'order' => $order,
            'time' => app(\App\Services\TimezoneResolver::class)->format($order->created_at, $order->franchise, 'd M, g:i A'),
        ]);
    }
}
