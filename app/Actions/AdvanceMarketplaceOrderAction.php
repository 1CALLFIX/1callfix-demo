<?php

namespace App\Actions;

use App\Events\MarketplaceOrderStatusUpdated;
use App\Jobs\MarketplaceDispatchJob;
use App\Models\MarketplaceOrder;
use App\Models\Setting;
use App\Notifications\MarketplaceOrderStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Support\FoodOrderClock;
use Illuminate\Support\Facades\DB;

/**
 * Phase 24 (Marketplace Foundation) — the store-side progression
 * (pending->accepted->preparing->ready), one action with a strict
 * transition map rather than three near-identical classes: unlike
 * Property Rental's Confirm/CheckIn/Complete (each with a genuinely
 * different real side effect — different notification payload, and
 * Complete alone triggers commission), these three transitions share
 * identical side effects (mutate status, stamp a timestamp where one
 * exists, write history, notify) and differ only in their label — building
 * three classes for that would be duplication, not consistency.
 * `ready`, for delivery orders, is what makes the order dispatch-eligible
 * (see MarketplaceDispatchJob) -- this action does not itself dispatch.
 */
class AdvanceMarketplaceOrderAction
{
    private const TRANSITIONS = [
        'accepted' => 'pending',
        'preparing' => 'accepted',
        'ready' => 'preparing',
    ];

    /** Food F2: `$prepMinutes` = the cooking time the restaurant confirms when it accepts (null = its usual time). */
    public function execute(int $orderId, string $toStatus, ?int $prepMinutes = null, ?\App\Support\AdminOverride $override = null): MarketplaceOrder
    {
        if ($prepMinutes !== null && ($prepMinutes < 1 || $prepMinutes > 240)) {
            throw new \RuntimeException('Enter a cooking time between 1 and 240 minutes.');
        }

        if (! array_key_exists($toStatus, self::TRANSITIONS)) {
            throw new \RuntimeException("'{$toStatus}' is not a valid store-side advance target.");
        }

        $order = DB::transaction(function () use ($orderId, $toStatus, $prepMinutes, $override) {
            $order = MarketplaceOrder::lockForUpdate()->findOrFail($orderId);
            $fromStatus = self::TRANSITIONS[$toStatus];

            if ($order->status !== $fromStatus) {
                throw new \RuntimeException("Order [{$orderId}] cannot move to '{$toStatus}' from status '{$order->status}'.");
            }

            // E-commerce Phase 0: the seller never starts on an online order whose money has not arrived.
            if ($toStatus === 'accepted' && $order->payment_method !== 'cash' && $order->payment_status !== 'paid') {
                throw new \RuntimeException("Order [{$orderId}] is not paid yet and cannot be accepted.");
            }

            // Food F2: past the accept time the order is being cancelled and refunded (food:expire-unaccepted).
            if ($toStatus === 'accepted' && $order->isFood() && $order->accept_by !== null && $order->accept_by->isPast()) {
                throw new \RuntimeException('This order waited too long and has been cancelled. The customer gets a full refund.');
            }

            $order->status = $toStatus;
            if ($toStatus === 'accepted') {
                $order->confirmed_at = now();
                // Food F2: the restaurant promises the food in its cooking time (plus busy minutes) from now.
                if ($order->isFood()) {
                    $order->ready_by = now()->addMinutes($prepMinutes ?? FoodOrderClock::prepMinutes($order));
                }
            }
            if ($toStatus === 'ready') {
                $order->ready_at = now();
                // E-commerce Phase 1: a pickup order gets a collection code the customer shows at the shop; the
                // seller enters it to hand over (a delivery order's code is made when a rider accepts).
                if ($order->order_type === 'pickup' && empty($order->delivery_otp)) {
                    $otpLength = (int) Setting::get('booking.otp_length', 4);
                    $order->delivery_otp = (string) random_int((int) (10 ** ($otpLength - 1)), (int) (10 ** $otpLength) - 1);
                }
            }
            $order->save();

            $order->statusHistory()->create([
                'status' => $toStatus,
                'changed_by' => $override?->by->id,
                'note' => $override?->note("Marked {$toStatus}") ?? "Order marked {$toStatus}",
                'changed_at' => now(),
            ]);

            $override?->log(MarketplaceOrder::class, $order->id, "order marked {$toStatus}");

            event(new MarketplaceOrderStatusUpdated($order));

            return $order->fresh();
        });

        // Food F2: a rider already on the way (called while cooking) is not searched for again.
        if ($toStatus === 'ready' && $order->order_type === 'delivery' && $order->assigned_worker_id === null) {
            MarketplaceDispatchJob::dispatch($order->id);
        }
        // Food F2: the rider call may already be due (short cooking time or a long lead).
        if ($order->isFood() && $toStatus === 'preparing') {
            app(\App\Services\Food\FoodRiderCaller::class)->callIfDue($order);
        }

        if ($order->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);
            $order->customer->notify(new MarketplaceOrderStatusNotification($toStatus, $order, $channels));
        }

        return $order;
    }
}
