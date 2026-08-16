<?php

namespace App\Actions;

use App\Events\PropertyReservationStatusUpdated;
use App\Models\PropertyReservation;
use App\Notifications\PropertyReservationStatusNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;

/**
 * Phase 22.7 (Property Rental) — confirmed → checked_in. No OTP (unlike
 * Service/Parcel/Taxi's handoff verification) — no evidence a property
 * check-in has a real-time verification event the app should gate; a
 * physical key/access handoff is outside this app's real-time scope,
 * same reasoning `ModuleCapabilities`' own `dispatch => false` row gives
 * for every rental sub-type.
 */
class CheckInPropertyReservationAction
{
    public function execute(int $reservationId): PropertyReservation
    {
        $reservation = DB::transaction(function () use ($reservationId) {
            $reservation = PropertyReservation::lockForUpdate()->findOrFail($reservationId);

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

            event(new PropertyReservationStatusUpdated($reservation));

            return $reservation->fresh();
        });

        if ($reservation->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);
            $reservation->customer->notify(new PropertyReservationStatusNotification('checked_in', $reservation, $channels));
        }

        return $reservation;
    }
}
