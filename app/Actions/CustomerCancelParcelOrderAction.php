<?php

namespace App\Actions;

use App\Models\ParcelOrder;
use App\Models\User;
use App\Services\CancellationService;

/**
 * A customer cancelling their own parcel (web page and customer API). Only until the parcel leaves the
 * sender (ParcelOrder::CUSTOMER_CANCELLABLE_STATUSES); after pickup, cancelling would leave a parcel in a
 * rider's hands, so it is refused and support handles it. Fee and refund are the existing parcel rules
 * (CancellationService: no fee while no rider is assigned) via AdminCancelParcelOrderAction.
 */
class CustomerCancelParcelOrderAction
{
    public function __construct(
        private AdminCancelParcelOrderAction $cancel,
        private CancellationService $cancellationService,
    ) {
    }

    /** The fee this customer would be charged if they cancelled now (0 while no rider is assigned). */
    public function fee(ParcelOrder $order): float
    {
        return $this->cancellationService->calculateFeeForParcelOrder($order);
    }

    public function execute(ParcelOrder $order, User $customer, string $reason): ParcelOrder
    {
        if ($order->customer_id !== $customer->id) {
            throw new \RuntimeException('Parcel order not found.');
        }
        if (! $order->fresh()->canBeCancelledByCustomer()) {
            throw new \RuntimeException('This parcel has already been picked up and can no longer be cancelled here. Please contact support.');
        }

        return $this->cancel->execute($order->id, $reason, 'customer');
    }
}
