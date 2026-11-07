<?php

namespace App\Services\Payments;

use App\Actions\AdminCancelMarketplaceOrderAction;
use App\Actions\AdminCancelParcelOrderAction;
use App\Models\MarketplaceOrder;
use App\Models\ParcelOrder;
use Illuminate\Support\Facades\Log;

/**
 * NO PAYMENT, NO BOOKING (owner 2026-11-07) for shop, food and parcel orders.
 *
 * An online order is only a payment attempt until Razorpay captures it (isAwaitingOnlinePayment()): the shop /
 * restaurant / rider / admin lists never show it, and it ends quietly (no "cancelled" message, no fee, shop stock
 * put back) when time runs out (marketplace:expire-unpaid / parcel:expire-unpaid) or when the customer orders again
 * (here). A payment that still arrives afterwards is refunded in full by MarketplacePaymentService /
 * ParcelPaymentService, as before. Services use UnpaidAttemptService.
 */
class OrderAttempts
{
    /** The customer's unpaid online shop / food orders end before a new one is placed (never two). */
    public function replaceShopFor(int $customerId): int
    {
        $ended = 0;
        foreach (MarketplaceOrder::where('customer_id', $customerId)->where('payment_method', 'online')
            ->where('payment_status', 'pending')->where('status', 'pending')->pluck('id') as $id) {
            try {
                app(AdminCancelMarketplaceOrderAction::class)->execute((int) $id, 'Replaced by the customer\'s new order before payment — no order was made', 'system', notifyCustomer: false);
                $ended++;
            } catch (\Throwable $e) {
                Log::warning("OrderAttempts: could not end unpaid shop order [{$id}]: ".$e->getMessage());
            }
        }

        return $ended;
    }

    /** The customer's unpaid online parcel orders end before a new one is placed (never two). */
    public function replaceParcelFor(int $customerId): int
    {
        $ended = 0;
        foreach (ParcelOrder::where('customer_id', $customerId)->where('payment_method', 'online')
            ->where('payment_status', 'pending')->where('status', 'pending')->pluck('id') as $id) {
            try {
                app(AdminCancelParcelOrderAction::class)->execute((int) $id, 'Replaced by the customer\'s new parcel order before payment — no order was made', 'system', notifyCustomer: false);
                $ended++;
            } catch (\Throwable $e) {
                Log::warning("OrderAttempts: could not end unpaid parcel order [{$id}]: ".$e->getMessage());
            }
        }

        return $ended;
    }
}
