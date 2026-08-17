<?php

namespace App\Actions;

use App\Events\RentalReservationStatusUpdated;
use App\Models\FieldWorker;
use App\Models\RentalReservation;
use App\Notifications\RentalReservationStatusNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;

/**
 * RENTAL MODULE IMPLEMENTATION -- pending -> confirmed. For With Driver
 * vehicle reservations this doubles as the brief's own "driver/vehicle
 * confirmation" step: an optional `$driverFieldWorkerId` may be supplied
 * here to associate the driver at the same time confirmation happens (no
 * automatic matching/dispatch is performed -- an explicit admin choice,
 * same as the brief's own "leave assignment to the existing architecture,
 * don't build Taxi-style matching" instruction).
 */
class ConfirmRentalReservationAction
{
    public function execute(int $reservationId, ?int $driverFieldWorkerId = null): RentalReservation
    {
        $reservation = DB::transaction(function () use ($reservationId, $driverFieldWorkerId) {
            $reservation = RentalReservation::lockForUpdate()->findOrFail($reservationId);

            if ($reservation->status !== 'pending') {
                throw new \RuntimeException("Reservation [{$reservationId}] cannot be confirmed from status '{$reservation->status}'.");
            }

            if ($driverFieldWorkerId !== null) {
                if ($reservation->rental_mode !== 'with_driver') {
                    throw new \RuntimeException('A driver can only be assigned to a with_driver rental reservation.');
                }
                FieldWorker::findOrFail($driverFieldWorkerId);
                $reservation->driver_field_worker_id = $driverFieldWorkerId;
            }

            $reservation->status = 'confirmed';
            $reservation->confirmed_at = now();
            $reservation->save();

            $reservation->statusHistory()->create([
                'status' => 'confirmed',
                'note' => 'Reservation confirmed',
                'changed_at' => now(),
            ]);

            event(new RentalReservationStatusUpdated($reservation));

            return $reservation->fresh();
        });

        if ($reservation->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);
            $reservation->customer->notify(new RentalReservationStatusNotification('confirmed', $reservation, $channels));
        }

        return $reservation;
    }
}
