<?php

namespace App\Observers;

use App\Models\Franchise;
use App\Models\ParcelOrder;
use App\Services\OrderCodeService;

// Phase 22.4 (Parcel) — exact mirror of BookingObserver's own pattern, just
// calling generateForParcel() instead of generate() so Parcel draws from
// its own sequence/format, never Service's.
class ParcelOrderObserver
{
    public function __construct(private OrderCodeService $orderCodeService)
    {
    }

    public function creating(ParcelOrder $order): void
    {
        if (empty($order->code)) {
            $franchise = $order->franchise ?? Franchise::findOrFail($order->franchise_id);
            $order->code = $this->orderCodeService->generateForParcel($franchise);
        }
    }
}
