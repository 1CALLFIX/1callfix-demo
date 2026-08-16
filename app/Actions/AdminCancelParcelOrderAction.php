<?php

namespace App\Actions;

use App\Events\ParcelOrderStatusUpdated;
use App\Models\ParcelOrder;
use App\Notifications\ParcelOrderStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\CancellationService;
use Illuminate\Support\Facades\DB;

/**
 * Phase 22.4 (Parcel) — the ParcelOrder counterpart to
 * AdminCancelBookingAction. Same structure: lock, guard against
 * re-cancelling a terminal order, calculate fee via the shared
 * CancellationService, mutate+history inside the transaction, refund as a
 * separate step outside it.
 */
class AdminCancelParcelOrderAction
{
    public function __construct(private CancellationService $cancellationService)
    {
    }

    public function execute(int $parcelOrderId, string $reason): ParcelOrder
    {
        $order = DB::transaction(function () use ($parcelOrderId, $reason) {
            $order = ParcelOrder::lockForUpdate()->findOrFail($parcelOrderId);

            if (in_array($order->status, ['delivered', 'cancelled'], true)) {
                throw new \RuntimeException("Parcel order is already {$order->status}, cannot cancel.");
            }

            $fee = $this->cancellationService->calculateFeeForParcelOrder($order);

            $order->status = 'cancelled';
            $order->cancellation_note = $reason;
            $order->cancellation_fee = $fee;
            $order->save();

            $order->statusHistory()->create([
                'status' => 'cancelled',
                'note' => "Cancelled by admin: {$reason}".($fee > 0 ? " (cancellation fee: {$fee})" : ''),
                'changed_at' => now(),
            ]);

            event(new ParcelOrderStatusUpdated($order));

            return $order->fresh();
        });

        $this->cancellationService->refundIfPaidForParcelOrder($order, (float) $order->cancellation_fee);

        if ($order->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);
            $order->customer->notify(new ParcelOrderStatusNotification('cancelled', $order, $channels));
        }

        return $order->fresh();
    }
}
