<?php

namespace App\Actions;

use App\Events\PropertyReservationStatusUpdated;
use App\Models\PropertyReservation;
use App\Notifications\PropertyReservationStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\CommissionService;
use Illuminate\Support\Facades\DB;

/**
 * Phase 22.7 (Property Rental) — checked_in → completed. The counterpart
 * to MarkParcelDeliveredAction/CompleteTaxiTripAction: mutate+history
 * inside the transaction, commission as a separate idempotent step
 * outside it, same placement reasoning every prior completion action in
 * this codebase already uses.
 */
class CompletePropertyReservationAction
{
    public function __construct(private CommissionService $commissionService)
    {
    }

    public function execute(int $reservationId): PropertyReservation
    {
        $reservation = DB::transaction(function () use ($reservationId) {
            $reservation = PropertyReservation::lockForUpdate()->findOrFail($reservationId);

            if ($reservation->status !== 'checked_in') {
                throw new \RuntimeException("Reservation [{$reservationId}] cannot be completed from status '{$reservation->status}'.");
            }

            $reservation->status = 'completed';
            $reservation->price_final = $reservation->price_quoted;
            $reservation->completed_at = now();
            $reservation->save();

            $reservation->statusHistory()->create([
                'status' => 'completed',
                'note' => 'Stay completed',
                'changed_at' => now(),
            ]);

            event(new PropertyReservationStatusUpdated($reservation));

            return $reservation->fresh();
        });

        $this->commissionService->applyForPropertyReservation($reservation);

        if ($reservation->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);
            $reservation->customer->notify(new PropertyReservationStatusNotification('completed', $reservation, $channels));
        }

        return $reservation;
    }
}
