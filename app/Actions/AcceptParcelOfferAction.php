<?php

namespace App\Actions;

use App\Events\ParcelOrderStatusUpdated;
use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\ParcelOrder;
use App\Models\Setting;
use App\Notifications\ParcelOrderStatusNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 22.4 (Parcel) — the ParcelOrder counterpart to AcceptBookingAction.
 * Same row-locked "only one worker wins the race" guarantee, same two-OTP-
 * generated-at-acceptance pattern (pickup_otp/delivery_otp, mirroring
 * start_otp/completion_otp) — reuses `booking.otp_length`'s own Setting key
 * rather than inventing a parallel `parcel.otp_length`, since OTP length is
 * a platform-wide security parameter, not a genuinely per-vertical one.
 */
class AcceptParcelOfferAction
{
    public function execute(int $parcelOrderId, FieldWorker $worker): ParcelOrder
    {
        $order = DB::transaction(function () use ($parcelOrderId, $worker) {
            $order = ParcelOrder::lockForUpdate()->findOrFail($parcelOrderId);

            if ($order->assigned_worker_id !== null) {
                throw new \RuntimeException('This parcel order has already been assigned to another rider.');
            }

            $attempt = DispatchAttempt::where('dispatchable_type', ParcelOrder::class)
                ->where('dispatchable_id', $parcelOrderId)
                ->where('notifiable_type', FieldWorker::class)
                ->where('notifiable_id', $worker->id)
                ->where('status', 'notified')
                ->first();

            if (!$attempt) {
                throw new \RuntimeException('This order offer is no longer available (expired or already withdrawn).');
            }

            $otpLength = (int) Setting::get('booking.otp_length', 4);
            $otpMin = (int) (10 ** ($otpLength - 1));
            $otpMax = (int) (10 ** $otpLength) - 1;

            $order->assigned_worker_id = $worker->id;
            $order->status = 'assigned';
            $order->pickup_otp = (string) random_int($otpMin, $otpMax);
            $order->delivery_otp = (string) random_int($otpMin, $otpMax);
            $order->save();

            $attempt->status = 'accepted';
            $attempt->responded_at = now();
            $attempt->save();

            DispatchAttempt::where('dispatchable_type', ParcelOrder::class)
                ->where('dispatchable_id', $parcelOrderId)
                ->where('id', '!=', $attempt->id)
                ->where('status', 'notified')
                ->update(['status' => 'timeout', 'responded_at' => now()]);

            $order->statusHistory()->create([
                'status' => 'assigned',
                'changed_by' => $worker->user_id,
                'note' => "Accepted by rider #{$worker->id}",
                'changed_at' => now(),
            ]);

            event(new ParcelOrderStatusUpdated($order));

            return $order->fresh();
        });

        if ($order->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);

            try {
                $order->customer->notify(new ParcelOrderStatusNotification('assigned', $order, $channels));
            } catch (\Throwable $e) {
                Log::error("Failed to deliver parcel order assigned status notification for parcel order [{$order->id}]: ".$e->getMessage());
            }
        }

        return $order;
    }
}
