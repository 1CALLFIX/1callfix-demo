<?php

namespace App\Actions;

use App\Models\FieldWorker;
use App\Models\ParcelOrder;
use App\Services\CommissionService;
use Illuminate\Support\Facades\DB;

/**
 * Parcel Stage 2 — return trip (owner decision 2026-10-31). Every drop is done and the rider has brought the
 * return (e.g. signed papers) back to the pickup point: the sender gives the rider their return code, and the
 * order is finished exactly like a one-way delivery (commission split, "delivered" message).
 */
class MarkParcelReturnedAction
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
            if (! $order->isReturning()) {
                throw new \RuntimeException('This parcel is not on its way back to the sender.');
            }
            if (empty($order->return_otp) || ! hash_equals((string) $order->return_otp, $enteredOtp)) {
                throw new \RuntimeException('Incorrect return code.');
            }

            $order->returned_at = now();
            MarkParcelDeliveredAction::finish($order, $worker, 'Return handed back to the sender with verified code');

            return $order->fresh();
        });

        MarkParcelDeliveredAction::afterFinish($order, $this->commissionService);

        return $order;
    }
}
