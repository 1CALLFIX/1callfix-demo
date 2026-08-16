<?php

namespace App\Actions;

use App\Events\TaxiRideStatusUpdated;
use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\Setting;
use App\Models\TaxiRide;
use App\Notifications\Support\ChannelResolver;
use App\Notifications\TaxiRideStatusNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 22.6 (Taxi) — the TaxiRide counterpart to AcceptParcelOfferAction.
 * One OTP only (start_otp — verifies the driver has the correct passenger
 * at pickup), unlike Parcel's two-handoff model.
 */
class AcceptTaxiOfferAction
{
    public function execute(int $taxiRideId, FieldWorker $worker): TaxiRide
    {
        $ride = DB::transaction(function () use ($taxiRideId, $worker) {
            $ride = TaxiRide::lockForUpdate()->findOrFail($taxiRideId);

            if ($ride->assigned_worker_id !== null) {
                throw new \RuntimeException('This ride has already been assigned to another driver.');
            }

            $attempt = DispatchAttempt::where('dispatchable_type', TaxiRide::class)
                ->where('dispatchable_id', $taxiRideId)
                ->where('notifiable_type', FieldWorker::class)
                ->where('notifiable_id', $worker->id)
                ->where('status', 'notified')
                ->first();

            if (!$attempt) {
                throw new \RuntimeException('This ride offer is no longer available (expired or already withdrawn).');
            }

            $otpLength = (int) Setting::get('booking.otp_length', 4);
            $otpMin = (int) (10 ** ($otpLength - 1));
            $otpMax = (int) (10 ** $otpLength) - 1;

            $ride->assigned_worker_id = $worker->id;
            $ride->status = 'assigned';
            $ride->start_otp = (string) random_int($otpMin, $otpMax);
            $ride->save();

            $attempt->status = 'accepted';
            $attempt->responded_at = now();
            $attempt->save();

            DispatchAttempt::where('dispatchable_type', TaxiRide::class)
                ->where('dispatchable_id', $taxiRideId)
                ->where('id', '!=', $attempt->id)
                ->where('status', 'notified')
                ->update(['status' => 'timeout', 'responded_at' => now()]);

            $ride->statusHistory()->create([
                'status' => 'assigned',
                'changed_by' => $worker->user_id,
                'note' => "Accepted by driver #{$worker->id}",
                'changed_at' => now(),
            ]);

            event(new TaxiRideStatusUpdated($ride));

            return $ride->fresh();
        });

        if ($ride->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $ride->zone_id, 'franchise_id' => $ride->franchise_id]);

            try {
                $ride->customer->notify(new TaxiRideStatusNotification('assigned', $ride, $channels));
            } catch (\Throwable $e) {
                Log::error("Failed to deliver taxi ride assigned status notification for ride [{$ride->id}]: ".$e->getMessage());
            }
        }

        return $ride;
    }
}
