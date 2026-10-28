<?php

namespace App\Actions;

use App\Events\ParcelOrderStatusUpdated;
use App\Models\FieldWorker;
use App\Models\ParcelOrder;
use App\Models\Payment;
use App\Notifications\ParcelOrderStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\Parcel\ParcelWaitingCharge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 22.4 (Parcel) — OTP-gated pickup confirmation, the counterpart to
 * StartBookingAction. `worker_en_route_pickup` (a real enum value on
 * parcel_orders.status) is deliberately not a separate transition action
 * in this MVP slice — a rider app can set it later via a lightweight
 * status-update endpoint without any schema change; picked_up is directly
 * reachable from `assigned` today, matching how `pending` (a whole state
 * this codebase's own real Service workflow also frequently skips over in
 * practice) already works.
 */
class MarkParcelPickedUpAction
{
    public function execute(int $parcelOrderId, FieldWorker $worker, string $enteredOtp): ParcelOrder
    {
        $order = DB::transaction(function () use ($parcelOrderId, $worker, $enteredOtp) {
            $order = ParcelOrder::lockForUpdate()->findOrFail($parcelOrderId);

            if ($order->assigned_worker_id !== $worker->id) {
                throw new \RuntimeException('This parcel order is not assigned to you.');
            }

            if (!in_array($order->status, ['assigned', 'worker_en_route_pickup'], true)) {
                throw new \RuntimeException("Parcel order [{$parcelOrderId}] cannot be marked picked up from status '{$order->status}'.");
            }

            if (empty($order->pickup_otp) || $order->pickup_otp !== $enteredOtp) {
                throw new \RuntimeException('Incorrect pickup OTP.');
            }

            // Defence in depth: an online order is only dispatched once paid, so this never trips in normal flow.
            if ($order->payment_method !== 'cash' && $order->payment_status !== 'paid') {
                throw new \RuntimeException('This parcel has not been paid yet. Do not pick it up.');
            }

            $order->status = 'picked_up';
            $order->picked_up_at = now();

            // Stage 1: waiting at pickup, from "I've arrived" to now. The sender pays it to the rider on the spot;
            // it is not part of the delivery price (ParcelWaitingCharge).
            if ($order->arrived_at_pickup_at) {
                $waiting = ParcelWaitingCharge::compute($order, $order->picked_up_at);
                $order->waiting_minutes = $waiting['minutes'];
                $order->waiting_charge = $waiting['charge'] > 0 ? $waiting['charge'] : null;
            }

            // Cash is collected from the sender at pickup (the rider's screen says so before they enter the
            // OTP). Recorded as a captured 'cash' payment so the order reconciles like any other paid order.
            if ($order->payment_method === 'cash' && ! $order->collectsCashAtDelivery() && $order->payment_status !== 'paid') {
                Payment::create([
                    'parcel_order_id' => $order->id,
                    'purpose' => 'parcel_order',
                    'amount' => $order->price_quoted,
                    'gateway' => 'cash',
                    'status' => 'captured',
                    'captured_at' => now(),
                ]);
                $order->payment_status = 'paid';
                $order->cash_collected_at = now();
            }

            $order->save();

            $order->statusHistory()->create([
                'status' => 'picked_up',
                'changed_by' => $worker->user_id,
                'note' => 'Picked up with verified OTP'.($order->waiting_charge ? " (waited {$order->waiting_minutes} min, waiting charge {$order->waiting_charge} paid to the rider)" : ''),
                'changed_at' => now(),
            ]);

            event(new ParcelOrderStatusUpdated($order));

            return $order->fresh();
        });

        if ($order->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);

            try {
                $order->customer->notify(new ParcelOrderStatusNotification('picked_up', $order, $channels));
            } catch (\Throwable $e) {
                Log::error("Failed to deliver parcel order picked_up status notification for parcel order [{$order->id}]: ".$e->getMessage());
            }
        }

        return $order;
    }
}
