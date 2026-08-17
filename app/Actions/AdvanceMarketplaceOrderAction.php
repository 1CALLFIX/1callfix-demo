<?php

namespace App\Actions;

use App\Events\MarketplaceOrderStatusUpdated;
use App\Jobs\MarketplaceDispatchJob;
use App\Models\MarketplaceOrder;
use App\Notifications\MarketplaceOrderStatusNotification;
use App\Notifications\Support\ChannelResolver;
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

    public function execute(int $orderId, string $toStatus): MarketplaceOrder
    {
        if (! array_key_exists($toStatus, self::TRANSITIONS)) {
            throw new \RuntimeException("'{$toStatus}' is not a valid store-side advance target.");
        }

        $order = DB::transaction(function () use ($orderId, $toStatus) {
            $order = MarketplaceOrder::lockForUpdate()->findOrFail($orderId);
            $fromStatus = self::TRANSITIONS[$toStatus];

            if ($order->status !== $fromStatus) {
                throw new \RuntimeException("Order [{$orderId}] cannot move to '{$toStatus}' from status '{$order->status}'.");
            }

            $order->status = $toStatus;
            if ($toStatus === 'accepted') {
                $order->confirmed_at = now();
            }
            if ($toStatus === 'ready') {
                $order->ready_at = now();
            }
            $order->save();

            $order->statusHistory()->create([
                'status' => $toStatus,
                'note' => "Order marked {$toStatus}",
                'changed_at' => now(),
            ]);

            event(new MarketplaceOrderStatusUpdated($order));

            return $order->fresh();
        });

        if ($toStatus === 'ready' && $order->order_type === 'delivery') {
            MarketplaceDispatchJob::dispatch($order->id);
        }

        if ($order->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);
            $order->customer->notify(new MarketplaceOrderStatusNotification($toStatus, $order, $channels));
        }

        return $order;
    }
}
