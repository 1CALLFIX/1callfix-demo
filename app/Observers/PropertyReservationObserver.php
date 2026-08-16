<?php

namespace App\Observers;

use App\Models\Franchise;
use App\Models\PropertyReservation;
use App\Services\OrderCodeService;

// Phase 22.7 (Property Rental) -- exact mirror of ParcelOrderObserver/TaxiRideObserver.
class PropertyReservationObserver
{
    public function __construct(private OrderCodeService $orderCodeService)
    {
    }

    public function creating(PropertyReservation $reservation): void
    {
        if (empty($reservation->code)) {
            $franchise = $reservation->franchise ?? Franchise::findOrFail($reservation->franchise_id);
            $reservation->code = $this->orderCodeService->generateForPropertyReservation($franchise);
        }
    }
}
