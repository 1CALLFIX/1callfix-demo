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
 * Pay at: Drop (owner decision 2026-10-24): the recipient refuses to pay the rider at the door. Handled by the
 * rider, not automated. Two rider steps:
 *
 *  refused():  picked_up → disputed ("returning to sender"); the sender is told to expect the parcel back.
 *  returned(): the rider hands it back and enters the SENDER's pickup code → cancelled, "returned to sender".
 *
 * No money moves automatically: nothing was collected, and paying the rider for the wasted trip would be a
 * manual money action (owner's approval model), not an automatic one.
 */
class ParcelRecipientRefusedAction
{
    public function refused(int $parcelOrderId, FieldWorker $worker): ParcelOrder
    {
        $order = DB::transaction(function () use ($parcelOrderId, $worker) {
            $order = ParcelOrder::lockForUpdate()->findOrFail($parcelOrderId);

            if ($order->assigned_worker_id !== $worker->id) {
                throw new \RuntimeException('This parcel order is not assigned to you.');
            }
            if (! $order->collectsCashAtDelivery() || $order->payment_status === 'paid'
                || ! in_array($order->status, ['picked_up', 'en_route_dropoff'], true)) {
                throw new \RuntimeException('Only an unpaid pay-at-drop parcel can be marked as refused.');
            }

            $order->status = 'disputed';
            $order->recipient_refused_at = now();
            $order->save();

            $order->statusHistory()->create([
                'status' => 'disputed',
                'changed_by' => $worker->user_id,
                'note' => 'Recipient refused to pay at the door — returning to sender',
                'changed_at' => now(),
            ]);

            event(new ParcelOrderStatusUpdated($order));

            return $order->fresh();
        });

        $this->tellSender($order, 'recipient_refused');

        return $order;
    }

    public function returned(int $parcelOrderId, FieldWorker $worker, string $senderCode): ParcelOrder
    {
        $order = DB::transaction(function () use ($parcelOrderId, $worker, $senderCode) {
            $order = ParcelOrder::lockForUpdate()->findOrFail($parcelOrderId);

            if ($order->assigned_worker_id !== $worker->id) {
                throw new \RuntimeException('This parcel order is not assigned to you.');
            }
            if ($order->status !== 'disputed') {
                throw new \RuntimeException('This parcel is not being returned.');
            }
            if (empty($order->pickup_otp) || $order->pickup_otp !== trim($senderCode)) {
                throw new \RuntimeException('Incorrect code. Ask the sender for their pickup code.');
            }

            $order->status = 'cancelled';
            $order->cancellation_note = 'Returned to sender — recipient refused to pay';
            $order->cancellation_fee = 0;
            $order->save();

            $order->statusHistory()->create([
                'status' => 'cancelled',
                'changed_by' => $worker->user_id,
                'note' => 'Returned to the sender with their pickup code',
                'changed_at' => now(),
            ]);

            event(new ParcelOrderStatusUpdated($order));

            return $order->fresh();
        });

        $this->tellSender($order, 'returned');

        return $order;
    }

    private function tellSender(ParcelOrder $order, string $event): void
    {
        if (! $order->customer) {
            return;
        }

        try {
            $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);
            $order->customer->notify(new ParcelOrderStatusNotification($event, $order, $channels));
        } catch (\Throwable $e) {
            Log::error("Failed to notify sender of parcel {$event} for parcel order [{$order->id}]: ".$e->getMessage());
        }
    }
}
