<?php

namespace App\Actions;

use App\Events\TaxiRideStatusUpdated;
use App\Models\FieldWorker;
use App\Models\TaxiRide;
use App\Notifications\Support\ChannelResolver;
use App\Notifications\TaxiRideStatusNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 22.6 (Taxi) — OTP-gated trip start, the counterpart to
 * MarkParcelPickedUpAction/StartBookingAction. `driver_en_route` (a real
 * enum value) is directly reachable through to `assigned` -> `trip_started`
 * in this MVP slice, same deliberate scope boundary Parcel's own pickup
 * action took for `worker_en_route_pickup`.
 */
class StartTaxiTripAction
{
    public function execute(int $taxiRideId, FieldWorker $worker, string $enteredOtp): TaxiRide
    {
        $ride = DB::transaction(function () use ($taxiRideId, $worker, $enteredOtp) {
            $ride = TaxiRide::lockForUpdate()->findOrFail($taxiRideId);

            if ($ride->assigned_worker_id !== $worker->id) {
                throw new \RuntimeException('This ride is not assigned to you.');
            }

            if (!in_array($ride->status, ['assigned', 'driver_en_route'], true)) {
                throw new \RuntimeException("Taxi ride [{$taxiRideId}] cannot be started from status '{$ride->status}'.");
            }

            if (empty($ride->start_otp) || $ride->start_otp !== $enteredOtp) {
                throw new \RuntimeException('Incorrect trip start OTP.');
            }

            $ride->status = 'trip_started';
            $ride->trip_started_at = now();
            $ride->save();

            $ride->statusHistory()->create([
                'status' => 'trip_started',
                'changed_by' => $worker->user_id,
                'note' => 'Trip started with verified OTP',
                'changed_at' => now(),
            ]);

            event(new TaxiRideStatusUpdated($ride));

            return $ride->fresh();
        });

        if ($ride->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $ride->zone_id, 'franchise_id' => $ride->franchise_id]);

            try {
                $ride->customer->notify(new TaxiRideStatusNotification('trip_started', $ride, $channels));
            } catch (\Throwable $e) {
                Log::error("Failed to deliver taxi ride trip_started status notification for ride [{$ride->id}]: ".$e->getMessage());
            }
        }

        return $ride;
    }
}
