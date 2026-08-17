<?php

namespace App\Actions;

use App\Events\RentalReservationStatusUpdated;
use App\Models\RentalReservation;
use App\Notifications\RentalReservationStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\CommissionService;
use Illuminate\Support\Facades\DB;

/**
 * RENTAL MODULE IMPLEMENTATION -- the final transition. Two legal prior
 * states, mirroring StartRentalReservationAction's own split:
 *   - With Driver:            active   -> completed
 *   - Self-Drive / Equipment: returned -> completed
 * Equipment additionally enforces the brief's own "inspection where
 * required": if the item's category has `requires_inspection = true`,
 * `inspected_at` must already be recorded (via InspectRentalReservationAction)
 * before completion is allowed.
 *
 * Same placement reasoning as CompletePropertyReservationAction: mutate +
 * history inside the transaction, commission as a separate idempotent step
 * outside it.
 */
class CompleteRentalReservationAction
{
    public function __construct(private CommissionService $commissionService)
    {
    }

    public function execute(int $reservationId): RentalReservation
    {
        $reservation = DB::transaction(function () use ($reservationId) {
            $reservation = RentalReservation::lockForUpdate()->findOrFail($reservationId);

            $expectedFrom = $reservation->rental_mode === 'with_driver' ? 'active' : 'returned';
            if ($reservation->status !== $expectedFrom) {
                throw new \RuntimeException("Reservation [{$reservationId}] cannot be completed from status '{$reservation->status}' (expected '{$expectedFrom}').");
            }

            if ($reservation->rental_type === 'equipment') {
                $reservation->loadMissing('rentable.equipmentCategory');
                $requiresInspection = (bool) $reservation->rentable?->equipmentCategory?->requires_inspection;
                if ($requiresInspection && ! $reservation->inspected_at) {
                    throw new \RuntimeException("Reservation [{$reservationId}] requires an inspection before it can be completed.");
                }
            }

            $reservation->status = 'completed';
            $reservation->price_final = $reservation->price_quoted;
            $reservation->completed_at = now();
            $reservation->save();

            $reservation->statusHistory()->create([
                'status' => 'completed',
                'note' => 'Rental completed',
                'changed_at' => now(),
            ]);

            event(new RentalReservationStatusUpdated($reservation));

            return $reservation->fresh();
        });

        $this->commissionService->applyForRentalReservation($reservation);

        if ($reservation->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);
            $reservation->customer->notify(new RentalReservationStatusNotification('completed', $reservation, $channels));
        }

        return $reservation;
    }
}
