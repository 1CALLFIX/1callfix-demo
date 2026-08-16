<?php

namespace App\Actions;

use App\Events\ParcelOrderStatusUpdated;
use App\Models\FieldWorker;
use App\Models\ParcelOrder;
use App\Notifications\ParcelOrderStatusNotification;
use App\Notifications\Support\ChannelResolver;
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

            $order->status = 'picked_up';
            $order->picked_up_at = now();
            $order->save();

            $order->statusHistory()->create([
                'status' => 'picked_up',
                'changed_by' => $worker->user_id,
                'note' => 'Picked up with verified OTP',
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
