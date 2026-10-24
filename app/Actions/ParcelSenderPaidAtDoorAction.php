<?php

namespace App\Actions;

use App\Events\ParcelOrderStatusUpdated;
use App\Models\FieldWorker;
use App\Models\ParcelOrder;
use App\Models\Payment;
use App\Notifications\ParcelOrderStatusNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pay at: Drop (owner decision 2026-10-24): the recipient refuses to pay at the door. The rider calls the sender,
 * the sender pays the rider on the spot (UPI to the rider, or cash), and the parcel is delivered as normal. No
 * return trip, no second charge.
 *
 * Recorded exactly like cash at the door: a captured 'cash' payment held by the rider, so delivery creates the
 * rider's cash due for the platform and franchise share (CommissionService). The rider then enters the delivery
 * code as usual (MarkParcelDeliveredAction skips its own collection because the order is already paid).
 */
class ParcelSenderPaidAtDoorAction
{
    public function execute(int $parcelOrderId, FieldWorker $worker): ParcelOrder
    {
        $order = DB::transaction(function () use ($parcelOrderId, $worker) {
            $order = ParcelOrder::lockForUpdate()->findOrFail($parcelOrderId);

            if ($order->assigned_worker_id !== $worker->id) {
                throw new \RuntimeException('This parcel order is not assigned to you.');
            }
            if (! $order->collectsCashAtDelivery() || $order->payment_status === 'paid'
                || ! in_array($order->status, ['picked_up', 'en_route_dropoff'], true)) {
                throw new \RuntimeException('Only an unpaid pay-at-drop parcel on its way can be paid by the sender.');
            }

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
            $order->recipient_refused_at = now();
            $order->save();

            $order->statusHistory()->create([
                'status' => $order->status,
                'changed_by' => $worker->user_id,
                'note' => 'Recipient refused to pay — the sender paid the rider on the spot',
                'changed_at' => now(),
            ]);

            event(new ParcelOrderStatusUpdated($order));

            return $order->fresh();
        });

        if ($order->customer) {
            try {
                $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);
                $order->customer->notify(new ParcelOrderStatusNotification('sender_paid', $order, $channels));
            } catch (\Throwable $e) {
                Log::error("Failed to notify sender of parcel sender_paid for parcel order [{$order->id}]: ".$e->getMessage());
            }
        }

        return $order;
    }
}
