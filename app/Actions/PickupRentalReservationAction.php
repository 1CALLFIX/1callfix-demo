<?php

namespace App\Actions;

use App\Events\RentalReservationStatusUpdated;
use App\Models\RentalReservation;
use App\Notifications\RentalReservationStatusNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;

/**
 * RENTAL MODULE IMPLEMENTATION -- confirmed -> picked_up. Only valid for
 * Self-Drive vehicle rentals and Equipment (the brief's own flows: "...->
 * pickup -> active rental -> ..."). With Driver rentals never touch this
 * status -- there is no physical customer pickup of a vehicle someone else
 * drives; With Driver goes confirmed -> active directly
 * (StartRentalReservationAction enforces this).
 */
class PickupRentalReservationAction
{
    public function execute(int $reservationId): RentalReservation
    {
        $reservation = DB::transaction(function () use ($reservationId) {
            $reservation = RentalReservation::lockForUpdate()->findOrFail($reservationId);

            if ($reservation->rental_mode === 'with_driver') {
                throw new \RuntimeException('With-driver rentals do not have a pickup step.');
            }

            if ($reservation->status !== 'confirmed') {
                throw new \RuntimeException("Reservation [{$reservationId}] cannot be marked picked up from status '{$reservation->status}'.");
            }

            $reservation->status = 'picked_up';
            $reservation->picked_up_at = now();
            $reservation->save();

            $reservation->statusHistory()->create([
                'status' => 'picked_up',
                'note' => 'Item picked up',
                'changed_at' => now(),
            ]);

            event(new RentalReservationStatusUpdated($reservation));

            return $reservation->fresh();
        });

        if ($reservation->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);
            $reservation->customer->notify(new RentalReservationStatusNotification('picked_up', $reservation, $channels));
        }

        return $reservation;
    }
}
