<?php

namespace App\Actions;

use App\Events\RentalReservationStatusUpdated;
use App\Models\RentalReservation;
use App\Notifications\RentalReservationStatusNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;

/**
 * RENTAL MODULE IMPLEMENTATION -- active -> returned. Only valid for
 * Self-Drive vehicle rentals and Equipment (same reasoning as
 * PickupRentalReservationAction -- With Driver has no physical return
 * step and goes straight from active to completed).
 */
class ReturnRentalReservationAction
{
    public function execute(int $reservationId): RentalReservation
    {
        $reservation = DB::transaction(function () use ($reservationId) {
            $reservation = RentalReservation::lockForUpdate()->findOrFail($reservationId);

            if ($reservation->rental_mode === 'with_driver') {
                throw new \RuntimeException('With-driver rentals do not have a return step.');
            }

            if ($reservation->status !== 'active') {
                throw new \RuntimeException("Reservation [{$reservationId}] cannot be marked returned from status '{$reservation->status}'.");
            }

            $reservation->status = 'returned';
            $reservation->returned_at = now();
            $reservation->save();

            $reservation->statusHistory()->create([
                'status' => 'returned',
                'note' => 'Item returned',
                'changed_at' => now(),
            ]);

            event(new RentalReservationStatusUpdated($reservation));

            return $reservation->fresh();
        });

        if ($reservation->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);
            $reservation->customer->notify(new RentalReservationStatusNotification('returned', $reservation, $channels));
        }

        return $reservation;
    }
}
