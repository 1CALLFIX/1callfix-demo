<?php

namespace App\Actions;

use App\Events\MarketplaceOrderStatusUpdated;
use App\Models\FieldWorker;
use App\Models\MarketplaceOrder;
use App\Models\Store;
use App\Models\User;
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

    /**
     * E-commerce Phase 1: `$seller` is the seller handing a pickup order over at the shop — they must enter the
     * customer's collection code (made at `ready`). The admin screen completes a pickup without one, as before.
     */
    public function execute(int $orderId, ?FieldWorker $worker = null, ?string $enteredOtp = null, ?User $seller = null): MarketplaceOrder
    {
        $order = DB::transaction(function () use ($orderId, $worker, $enteredOtp, $seller) {
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
            } elseif ($seller !== null) {
                if (! Store::whereKey($order->store_id)->whereHas('provider', fn ($q) => $q->where('user_id', $seller->id))->exists()) {
                    throw new \RuntimeException('This order is not from your shop.');
                }
                if (empty($order->delivery_otp) || $order->delivery_otp !== trim((string) $enteredOtp)) {
                    throw new \RuntimeException('Incorrect collection code.');
                }
            }

            $order->status = 'completed';
            $order->price_final = $order->total_amount;
            $order->completed_at = now();
            $order->save();

            $order->statusHistory()->create([
                'status' => 'completed',
                'changed_by' => $worker?->user_id ?? $seller?->id,
                'note' => $order->order_type === 'delivery' ? 'Delivered with verified OTP' : 'Collected by customer',
                'changed_at' => now(),
            ]);

            $worker?->increment('jobs_completed');

            event(new MarketplaceOrderStatusUpdated($order));

            return $order->fresh();
        });

        $this->commissionService->applyForMarketplaceOrder($order);

        // The coupon's benefit is realised on completion.
        if ($usage = \App\Models\CouponUsage::where('marketplace_order_id', $order->id)->where('status', 'reserved')->first()) {
            app(\App\Services\Coupons\CouponService::class)->confirm($usage);
        }

        if ($order->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);
            $order->customer->notify(new MarketplaceOrderStatusNotification('completed', $order, $channels));
        }

        return $order;
    }
}
