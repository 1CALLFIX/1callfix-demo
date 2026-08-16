<?php

namespace App\Observers;

use App\Models\Franchise;
use App\Models\TaxiRide;
use App\Services\OrderCodeService;

// Phase 22.6 (Taxi) -- exact mirror of ParcelOrderObserver's own pattern.
class TaxiRideObserver
{
    public function __construct(private OrderCodeService $orderCodeService)
    {
    }

    public function creating(TaxiRide $ride): void
    {
        if (empty($ride->code)) {
            $franchise = $ride->franchise ?? Franchise::findOrFail($ride->franchise_id);
            $ride->code = $this->orderCodeService->generateForTaxi($franchise);
        }
    }
}
