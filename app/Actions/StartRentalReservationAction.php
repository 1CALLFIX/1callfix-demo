<?php

namespace App\Actions;

use App\Events\RentalReservationStatusUpdated;
use App\Models\RentalReservation;
use App\Notifications\RentalReservationStatusNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;

/**
 * RENTAL MODULE IMPLEMENTATION -- the "active rental" transition. Two
 * legal prior states depending on rental_mode, both from the brief's own
 * given flows:
 *   - With Driver:            confirmed -> active   (skips picked_up)
 *   - Self-Drive / Equipment: picked_up -> active
 */
class StartRentalReservationAction
{
    public function execute(int $reservationId): RentalReservation
    {
        $reservation = DB::transaction(function () use ($reservationId) {
            $reservation = RentalReservation::lockForUpdate()->findOrFail($reservationId);

            $expectedFrom = $reservation->rental_mode === 'with_driver' ? 'confirmed' : 'picked_up';
            if ($reservation->status !== $expectedFrom) {
                throw new \RuntimeException("Reservation [{$reservationId}] cannot be started from status '{$reservation->status}' (expected '{$expectedFrom}').");
            }

            $reservation->status = 'active';
            $reservation->save();

            $reservation->statusHistory()->create([
                'status' => 'active',
                'note' => 'Rental started',
                'changed_at' => now(),
            ]);

            event(new RentalReservationStatusUpdated($reservation));

            return $reservation->fresh();
        });

        if ($reservation->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);
            $reservation->customer->notify(new RentalReservationStatusNotification('active', $reservation, $channels));
        }

        return $reservation;
    }
}
