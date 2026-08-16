<?php

namespace App\Actions;

use App\Events\PropertyReservationStatusUpdated;
use App\Models\PropertyReservation;
use App\Notifications\PropertyReservationStatusNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;

/**
 * Phase 22.7 (Property Rental) — pending → confirmed. A deliberately
 * separate step from creation (RESERVATION → PAYMENT → CONFIRMATION, per
 * the mission's own given flow), admin/operator-triggered — no evidence
 * supports an auto-confirm-only ("instant booking") policy as the sole
 * real behavior, so this isn't assumed.
 */
class ConfirmPropertyReservationAction
{
    public function execute(int $reservationId): PropertyReservation
    {
        $reservation = DB::transaction(function () use ($reservationId) {
            $reservation = PropertyReservation::lockForUpdate()->findOrFail($reservationId);

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

            event(new PropertyReservationStatusUpdated($reservation));

            return $reservation->fresh();
        });

        if ($reservation->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);
            $reservation->customer->notify(new PropertyReservationStatusNotification('confirmed', $reservation, $channels));
        }

        return $reservation;
    }
}
