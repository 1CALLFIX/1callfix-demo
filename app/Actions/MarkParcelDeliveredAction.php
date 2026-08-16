<?php

namespace App\Actions;

use App\Events\ParcelOrderStatusUpdated;
use App\Models\FieldWorker;
use App\Models\ParcelOrder;
use App\Notifications\ParcelOrderStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\CommissionService;
use Illuminate\Support\Facades\DB;

/**
 * Phase 22.4 (Parcel) — the ParcelOrder counterpart to CompleteBookingAction.
 * Same structure: lock, validate, OTP-check, mutate+history inside one
 * transaction; commission/loyalty/notification as separate, idempotent
 * steps outside it, same reasoning CompleteBookingAction's own comments
 * give (don't hold the row lock through wallet-crediting or an external
 * call). Loyalty/referral are deliberately NOT wired here — Parcel has no
 * evidence-backed loyalty-points-per-parcel or referral-qualifying-event
 * decision (KNOWN_RISKS_AND_DECISIONS.md items 1/2 are Service/Customer-
 * referral-specific; extending them to Parcel would be inventing a new
 * business rule, not implementing an existing one).
 */
class MarkParcelDeliveredAction
{
    public function __construct(private CommissionService $commissionService)
    {
    }

    public function execute(int $parcelOrderId, FieldWorker $worker, string $enteredOtp): ParcelOrder
    {
        $order = DB::transaction(function () use ($parcelOrderId, $worker, $enteredOtp) {
            $order = ParcelOrder::lockForUpdate()->findOrFail($parcelOrderId);

            if ($order->assigned_worker_id !== $worker->id) {
                throw new \RuntimeException('This parcel order is not assigned to you.');
            }

            if (!in_array($order->status, ['picked_up', 'en_route_dropoff'], true)) {
                throw new \RuntimeException("Parcel order [{$parcelOrderId}] cannot be marked delivered from status '{$order->status}'.");
            }

            if (empty($order->delivery_otp) || $order->delivery_otp !== $enteredOtp) {
                throw new \RuntimeException('Incorrect delivery OTP.');
            }

            $order->status = 'delivered';
            $order->price_final = $order->price_quoted;
            $order->delivered_at = now();
            $order->save();

            $order->statusHistory()->create([
                'status' => 'delivered',
                'changed_by' => $worker->user_id,
                'note' => 'Delivered with verified OTP',
                'changed_at' => now(),
            ]);

            $worker->increment('jobs_completed');

            event(new ParcelOrderStatusUpdated($order));

            return $order->fresh();
        });

        // Commission split runs after the delivery transaction commits --
        // same placement as CompleteBookingAction's own applyForBooking()
        // call, deliberately outside the row lock.
        $this->commissionService->applyForParcelOrder($order);

        if ($order->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);
            $order->customer->notify(new ParcelOrderStatusNotification('delivered', $order, $channels));
        }

        return $order;
    }
}
