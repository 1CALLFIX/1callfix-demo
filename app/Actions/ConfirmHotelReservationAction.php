<?php

namespace App\Actions;

use App\Events\HotelReservationStatusUpdated;
use App\Models\HotelReservation;
use App\Notifications\HotelReservationStatusNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;

/** HOTEL / STAY BOOKING MODULE — pending → confirmed. Exact mirror of ConfirmPropertyReservationAction. */
class ConfirmHotelReservationAction
{
    public function execute(int $reservationId): HotelReservation
    {
        $reservation = DB::transaction(function () use ($reservationId) {
            $reservation = HotelReservation::lockForUpdate()->findOrFail($reservationId);

            if ($reservation->status !== 'pending') {
                throw new \RuntimeException("Reservation [{$reservationId}] cannot be confirmed from status '{$reservation->status}'.");
            }

            $reservation->status = 'confirmed';
            $reservation->confirmed_at = now();
            $reservation->save();

            $reservation->statusHistory()->create([
                'status' => 'confirmed',
                'note' => 'Reservation confirmed',
                'changed_at' => now(),
            ]);

            event(new HotelReservationStatusUpdated($reservation));

            return $reservation->fresh();
        });

        if ($reservation->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);
            $reservation->customer->notify(new HotelReservationStatusNotification('confirmed', $reservation, $channels));
        }

        return $reservation;
    }
}
