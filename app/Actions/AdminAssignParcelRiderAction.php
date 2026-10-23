<?php

namespace App\Actions;

use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\ParcelOrder;
use Illuminate\Support\Facades\DB;

/**
 * Admin / call-centre assigns a rider by hand — the path ParcelDispatchJob hands over to when its rounds run
 * out ("leaving for manual admin assignment"), which had no screen behind it until now. Records the offer as
 * an attempt and accepts it through AcceptParcelOfferAction, so OTPs, status history, the customer
 * notification and the recipient SMS are exactly what a rider's own acceptance produces.
 */
class AdminAssignParcelRiderAction
{
    public function __construct(private AcceptParcelOfferAction $accept)
    {
    }

    public function execute(int $parcelOrderId, int $fieldWorkerId): ParcelOrder
    {
        $worker = FieldWorker::with('user')->findOrFail($fieldWorkerId);

        DB::transaction(function () use ($parcelOrderId, $worker) {
            $order = ParcelOrder::lockForUpdate()->findOrFail($parcelOrderId);

            if (! in_array($order->status, ['pending', 'searching_worker'], true) || $order->assigned_worker_id !== null) {
                throw new \RuntimeException('Only an order that is still looking for a rider can be assigned.');
            }
            if ($order->isAwaitingOnlinePayment()) {
                throw new \RuntimeException('This online order has not been paid yet.');
            }
            if (! $worker->is_active || ! $worker->hasCapability('parcel_rider') || $worker->franchise_id !== $order->franchise_id) {
                throw new \RuntimeException('Pick an active parcel rider from this order\'s franchise.');
            }
            $busy = ParcelOrder::where('assigned_worker_id', $worker->id)->whereIn('status', ParcelOrder::ACTIVE_RIDER_STATUSES)->exists();
            if ($busy) {
                throw new \RuntimeException('That rider is already on another parcel.');
            }

            DispatchAttempt::updateOrCreate(
                [
                    'dispatchable_type' => ParcelOrder::class, 'dispatchable_id' => $order->id,
                    'notifiable_type' => FieldWorker::class, 'notifiable_id' => $worker->id,
                ],
                ['status' => 'notified', 'notified_at' => now(), 'responded_at' => null]
            );
        });

        return $this->accept->execute($parcelOrderId, $worker);
    }
}
