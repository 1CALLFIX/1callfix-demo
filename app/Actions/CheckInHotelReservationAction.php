<?php

namespace App\Actions;

use App\Events\HotelReservationStatusUpdated;
use App\Models\HotelReservation;
use App\Notifications\HotelReservationStatusNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;

/** HOTEL / STAY BOOKING MODULE — confirmed → checked_in. Exact mirror of CheckInPropertyReservationAction. */
class CheckInHotelReservationAction
{
    public function execute(int $reservationId): HotelReservation
    {
        $reservation = DB::transaction(function () use ($reservationId) {
            $reservation = HotelReservation::lockForUpdate()->findOrFail($reservationId);

            if ($reservation->status !== 'confirmed') {
                throw new \RuntimeException("Reservation [{$reservationId}] cannot be checked in from status '{$reservation->status}'.");
            }

            $reservation->status = 'checked_in';
            $reservation->checked_in_at = now();
            $reservation->save();

            $reservation->statusHistory()->create([
                'status' => 'checked_in',
                'note' => 'Guest checked in',
                'changed_at' => now(),
            ]);

            event(new HotelReservationStatusUpdated($reservation));

            return $reservation->fresh();
        });

        if ($reservation->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);
            $reservation->customer->notify(new HotelReservationStatusNotification('checked_in', $reservation, $channels));
        }

        return $reservation;
    }
}
