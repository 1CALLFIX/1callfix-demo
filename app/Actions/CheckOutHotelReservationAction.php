<?php

namespace App\Actions;

use App\Events\HotelReservationStatusUpdated;
use App\Models\HotelReservation;
use App\Notifications\HotelReservationStatusNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;

/**
 * HOTEL / STAY BOOKING MODULE — checked_in → checked_out. The one lifecycle
 * step Property Rental doesn't have: the mission brief explicitly asks for
 * a distinct check-out moment, separate from administrative completion
 * (`CompleteHotelReservationAction`, which applies commission) — a real
 * hotel stay has a guest-departs moment that isn't necessarily the same
 * instant as settlement/finalization.
 */
class CheckOutHotelReservationAction
{
    public function execute(int $reservationId): HotelReservation
    {
        $reservation = DB::transaction(function () use ($reservationId) {
            $reservation = HotelReservation::lockForUpdate()->findOrFail($reservationId);

            if ($reservation->status !== 'checked_in') {
                throw new \RuntimeException("Reservation [{$reservationId}] cannot be checked out from status '{$reservation->status}'.");
            }

            $reservation->status = 'checked_out';
            $reservation->checked_out_at = now();
            $reservation->save();

            $reservation->statusHistory()->create([
                'status' => 'checked_out',
                'note' => 'Guest checked out',
                'changed_at' => now(),
            ]);

            event(new HotelReservationStatusUpdated($reservation));

            return $reservation->fresh();
        });

        if ($reservation->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);
            $reservation->customer->notify(new HotelReservationStatusNotification('checked_out', $reservation, $channels));
        }

        return $reservation;
    }
}
