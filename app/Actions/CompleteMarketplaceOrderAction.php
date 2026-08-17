<?php

namespace App\Actions;

use App\Events\MarketplaceOrderStatusUpdated;
use App\Models\FieldWorker;
use App\Models\MarketplaceOrder;
use App\Notifications\MarketplaceOrderStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\CommissionService;
use Illuminate\Support\Facades\DB;

/**
 * Phase 24 (Marketplace Foundation) — ready -> completed. Covers BOTH
 * order types with one action rather than two: a `delivery` order is
 * completed by its assigned rider, OTP-verified (the real handoff-to-
 * customer moment); a `pickup` order is completed by the store itself
 * (the customer collects in person -- no rider, no OTP, matching 6amMart's
 * real `order_type` distinction). `$worker`/`$otp` are both null for the
 * pickup path; both required and verified for the delivery path.
 */
class CompleteMarketplaceOrderAction
{
    public function __construct(private CommissionService $commissionService)
    {
    }

    public function execute(int $orderId, ?FieldWorker $worker = null, ?string $enteredOtp = null): MarketplaceOrder
    {
        $order = DB::transaction(function () use ($orderId, $worker, $enteredOtp) {
            $order = MarketplaceOrder::lockForUpdate()->findOrFail($orderId);

            if ($order->status !== 'ready') {
                throw new \RuntimeException("Order [{$orderId}] cannot be completed from status '{$order->status}'.");
            }

            if ($order->order_type === 'delivery') {
                if (! $worker || $order->assigned_worker_id !== $worker->id) {
                    throw new \RuntimeException('This order is not assigned to you.');
                }
                if (empty($order->delivery_otp) || $order->delivery_otp !== $enteredOtp) {
                    throw new \RuntimeException('Incorrect delivery OTP.');
                }
            }

            $order->status = 'completed';
            $order->price_final = $order->total_amount;
            $order->completed_at = now();
            $order->save();

            $order->statusHistory()->create([
                'status' => 'completed',
                'changed_by' => $worker?->user_id,
                'note' => $order->order_type === 'delivery' ? 'Delivered with verified OTP' : 'Collected by customer',
                'changed_at' => now(),
            ]);

            $worker?->increment('jobs_completed');

            event(new MarketplaceOrderStatusUpdated($order));

            return $order->fresh();
        });

        $this->commissionService->applyForMarketplaceOrder($order);

        if ($order->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);
            $order->customer->notify(new MarketplaceOrderStatusNotification('completed', $order, $channels));
        }

        return $order;
    }
}
