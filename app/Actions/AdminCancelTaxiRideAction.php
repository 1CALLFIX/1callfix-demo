<?php

namespace App\Actions;

use App\Events\TaxiRideStatusUpdated;
use App\Models\TaxiRide;
use App\Notifications\Support\ChannelResolver;
use App\Notifications\TaxiRideStatusNotification;
use App\Services\CancellationService;
use Illuminate\Support\Facades\DB;

/** Phase 22.6 (Taxi) — the TaxiRide counterpart to AdminCancelParcelOrderAction/AdminCancelBookingAction. */
class AdminCancelTaxiRideAction
{
    public function __construct(private CancellationService $cancellationService)
    {
    }

    public function execute(int $taxiRideId, string $reason): TaxiRide
    {
        $ride = DB::transaction(function () use ($taxiRideId, $reason) {
            $ride = TaxiRide::lockForUpdate()->findOrFail($taxiRideId);

            if (in_array($ride->status, ['trip_completed', 'cancelled'], true)) {
                throw new \RuntimeException("Taxi ride is already {$ride->status}, cannot cancel.");
            }

            $fee = $this->cancellationService->calculateFeeForTaxiRide($ride);

            $ride->status = 'cancelled';
            $ride->cancellation_note = $reason;
            $ride->cancellation_fee = $fee;
            $ride->save();

            $ride->statusHistory()->create([
                'status' => 'cancelled',
                'note' => "Cancelled by admin: {$reason}".($fee > 0 ? " (cancellation fee: {$fee})" : ''),
                'changed_at' => now(),
            ]);

            event(new TaxiRideStatusUpdated($ride));

            return $ride->fresh();
        });

        $this->cancellationService->refundIfPaidForTaxiRide($ride, (float) $ride->cancellation_fee);

        if ($ride->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $ride->zone_id, 'franchise_id' => $ride->franchise_id]);
            $ride->customer->notify(new TaxiRideStatusNotification('cancelled', $ride, $channels));
        }

        return $ride->fresh();
    }
}
