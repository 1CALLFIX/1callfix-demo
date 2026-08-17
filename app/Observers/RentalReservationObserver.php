<?php

namespace App\Observers;

use App\Models\Franchise;
use App\Models\RentalReservation;
use App\Services\OrderCodeService;

// RENTAL MODULE IMPLEMENTATION -- exact mirror of PropertyReservationObserver, for the shared Vehicle/Equipment engine.
class RentalReservationObserver
{
    public function __construct(private OrderCodeService $orderCodeService)
    {
    }

    public function creating(RentalReservation $reservation): void
    {
        if (empty($reservation->code)) {
            $franchise = $reservation->franchise ?? Franchise::findOrFail($reservation->franchise_id);
            $typeSegment = $reservation->rental_type === 'vehicle' ? 'VEH' : 'EQP';
            $reservation->code = $this->orderCodeService->generateForRentalReservation($franchise, $typeSegment);
        }
    }
}
