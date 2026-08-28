<?php

namespace App\Observers;

use App\Models\BookingBundle;
use App\Models\Franchise;
use App\Services\OrderCodeService;

// Phase E1 (Multi-Service Booking) — exact mirror of BookingObserver /
// ParcelOrderObserver / HotelReservationObserver: auto-generate the bundle's
// own code on first save, from its own sequence
// (generateForBookingBundle() -> booking_bundle_sequences, `-BDL-` segment),
// never Service's counter. Never overwrites a code already set (tests/seeders).
class BookingBundleObserver
{
    public function __construct(private OrderCodeService $orderCodeService)
    {
    }

    public function creating(BookingBundle $bundle): void
    {
        if (empty($bundle->code)) {
            $franchise = $bundle->franchise ?? Franchise::findOrFail($bundle->franchise_id);
            $bundle->code = $this->orderCodeService->generateForBookingBundle($franchise);
        }
    }
}
