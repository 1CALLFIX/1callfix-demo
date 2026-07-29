<?php

namespace App\Observers;

use App\Models\Booking;
use App\Services\OrderCodeService;

class BookingObserver
{
    public function __construct(private OrderCodeService $orderCodeService)
    {
    }

    /**
     * Auto-generate booking.code before the record is saved for the first time.
     * Never overwrites a code if one is already set (e.g. in tests/seeders).
     */
    public function creating(Booking $booking): void
    {
        if (empty($booking->code)) {
            $franchise = $booking->franchise ?? \App\Models\Franchise::findOrFail($booking->franchise_id);
            $booking->code = $this->orderCodeService->generate($franchise);
        }
    }
}
