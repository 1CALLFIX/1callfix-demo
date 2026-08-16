<?php

namespace App\Actions;

use App\Events\TaxiRideStatusUpdated;
use App\Models\FieldWorker;
use App\Models\TaxiRide;
use App\Notifications\Support\ChannelResolver;
use App\Notifications\TaxiRideStatusNotification;
use App\Services\CommissionService;
use App\Services\DispatchService;
use Illuminate\Support\Facades\DB;

/**
 * Phase 22.6 (Taxi) — the TaxiRide counterpart to MarkParcelDeliveredAction/
 * CompleteBookingAction. No OTP at trip end (unlike start) — the same
 * "one real verification point" reasoning the migration's own docblock
 * gives. `distance_km` is computed via the same DispatchService::
 * haversineKm() primitive dispatch itself already uses, when a dropoff
 * address is known; `price_final` stays equal to `price_quoted` today —
 * a real distance/time-based fare model is a named, not-invented business
 * decision (KNOWN_RISKS_AND_DECISIONS.md item 31's Taxi counterpart).
 */
class CompleteTaxiTripAction
{
    public function __construct(
        private CommissionService $commissionService,
        private DispatchService $dispatchService,
    ) {
    }

    public function execute(int $taxiRideId, FieldWorker $worker): TaxiRide
    {
        $ride = DB::transaction(function () use ($taxiRideId, $worker) {
            $ride = TaxiRide::lockForUpdate()->findOrFail($taxiRideId);

            if ($ride->assigned_worker_id !== $worker->id) {
                throw new \RuntimeException('This ride is not assigned to you.');
            }

            if ($ride->status !== 'trip_started') {
                throw new \RuntimeException("Taxi ride [{$taxiRideId}] cannot be completed from status '{$ride->status}'.");
            }

            $ride->loadMissing(['pickupAddress', 'dropoffAddress']);
            if ($ride->pickupAddress && $ride->dropoffAddress) {
                $ride->distance_km = $this->dispatchService->haversineKm(
                    (float) $ride->pickupAddress->lat, (float) $ride->pickupAddress->lng,
                    (float) $ride->dropoffAddress->lat, (float) $ride->dropoffAddress->lng
                );
            }

            $ride->status = 'trip_completed';
            $ride->price_final = $ride->price_quoted;
            $ride->trip_completed_at = now();
            $ride->save();

            $ride->statusHistory()->create([
                'status' => 'trip_completed',
                'changed_by' => $worker->user_id,
                'note' => 'Trip completed',
                'changed_at' => now(),
            ]);

            $worker->increment('jobs_completed');

            event(new TaxiRideStatusUpdated($ride));

            return $ride->fresh();
        });

        $this->commissionService->applyForTaxiRide($ride);

        if ($ride->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $ride->zone_id, 'franchise_id' => $ride->franchise_id]);
            $ride->customer->notify(new TaxiRideStatusNotification('trip_completed', $ride, $channels));
        }

        return $ride;
    }
}
