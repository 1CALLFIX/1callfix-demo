<?php

namespace App\Actions;

use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\ParcelOrder;

/**
 * A rider turning down a parcel offer from the rider web area. Marks only their own still-open attempt
 * 'rejected'; ParcelDispatchJob's next round never re-offers the order to them (it excludes every worker
 * already offered) and moves on to the next nearest rider.
 */
class DeclineParcelOfferAction
{
    public function execute(int $parcelOrderId, FieldWorker $worker): void
    {
        $updated = DispatchAttempt::where('dispatchable_type', ParcelOrder::class)
            ->where('dispatchable_id', $parcelOrderId)
            ->where('notifiable_type', FieldWorker::class)
            ->where('notifiable_id', $worker->id)
            ->where('status', 'notified')
            ->update(['status' => 'rejected', 'responded_at' => now()]);

        if ($updated === 0) {
            throw new \RuntimeException('This offer is no longer available.');
        }
    }
}
