<?php

namespace App\Observers;

use App\Models\Franchise;
use App\Models\HotelReservation;
use App\Services\OrderCodeService;

// HOTEL / STAY BOOKING MODULE -- exact mirror of PropertyReservationObserver/RentalReservationObserver.
class HotelReservationObserver
{
    public function __construct(private OrderCodeService $orderCodeService)
    {
    }

    public function creating(HotelReservation $reservation): void
    {
        if (empty($reservation->code)) {
            $franchise = $reservation->franchise ?? Franchise::findOrFail($reservation->franchise_id);
            $reservation->code = $this->orderCodeService->generateForHotelReservation($franchise);
        }
    }
}
