<?php

namespace App\Actions;

use App\Events\MarketplaceOrderStatusUpdated;
use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\MarketplaceOrder;
use App\Models\Setting;
use App\Notifications\MarketplaceOrderStatusNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 24 (Marketplace Foundation) — the MarketplaceOrder counterpart to
 * AcceptParcelOfferAction. Same row-locked "only one rider wins the race"
 * guarantee. Order status stays `ready` (it does not have its own
 * "assigned" state — see architecture doc §4a; `assigned_worker_id` being
 * non-null IS the assigned signal) -- a single `delivery_otp` is generated
 * here (not two, see the marketplace_orders migration's own comment).
 */
class AcceptMarketplaceDeliveryOfferAction
{
    public function execute(int $orderId, FieldWorker $worker): MarketplaceOrder
    {
        $order = DB::transaction(function () use ($orderId, $worker) {
            $order = MarketplaceOrder::lockForUpdate()->findOrFail($orderId);

            if ($order->status !== 'ready' || $order->order_type !== 'delivery') {
                throw new \RuntimeException("Order [{$orderId}] is not open for delivery-rider acceptance.");
            }

            if ($order->assigned_worker_id !== null) {
                throw new \RuntimeException('This order has already been assigned to another rider.');
            }

            $attempt = DispatchAttempt::where('dispatchable_type', MarketplaceOrder::class)
                ->where('dispatchable_id', $orderId)
                ->where('notifiable_type', FieldWorker::class)
                ->where('notifiable_id', $worker->id)
                ->where('status', 'notified')
                ->first();

            if (! $attempt) {
                throw new \RuntimeException('This order offer is no longer available (expired or already withdrawn).');
            }

            $otpLength = (int) Setting::get('booking.otp_length', 4);
            $otpMin = (int) (10 ** ($otpLength - 1));
            $otpMax = (int) (10 ** $otpLength) - 1;

            $order->assigned_worker_id = $worker->id;
            $order->delivery_otp = (string) random_int($otpMin, $otpMax);
            $order->save();

            $attempt->status = 'accepted';
            $attempt->responded_at = now();
            $attempt->save();

            DispatchAttempt::where('dispatchable_type', MarketplaceOrder::class)
                ->where('dispatchable_id', $orderId)
                ->where('id', '!=', $attempt->id)
                ->where('status', 'notified')
                ->update(['status' => 'timeout', 'responded_at' => now()]);

            $order->statusHistory()->create([
                'status' => 'ready',
                'changed_by' => $worker->user_id,
                'note' => "Delivery accepted by rider #{$worker->id}",
                'changed_at' => now(),
            ]);

            event(new MarketplaceOrderStatusUpdated($order));

            return $order->fresh();
        });

        if ($order->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);

            try {
                $order->customer->notify(new MarketplaceOrderStatusNotification('rider_assigned', $order, $channels));
            } catch (\Throwable $e) {
                Log::error("Failed to deliver marketplace order rider-assigned notification for order [{$order->id}]: ".$e->getMessage());
            }
        }

        return $order;
    }
}
