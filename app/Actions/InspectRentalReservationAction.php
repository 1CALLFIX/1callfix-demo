<?php

namespace App\Actions;

use App\Models\RentalReservation;
use Illuminate\Support\Facades\DB;

/**
 * RENTAL MODULE IMPLEMENTATION -- records the optional post-return
 * inspection step the brief names for Equipment ("... -> return ->
 * inspection where required -> completion"). Deliberately NOT a status
 * transition of its own -- "where required" means this is conditional
 * (see `equipment_categories.requires_inspection`), so adding a dedicated
 * FSM status for it would force every equipment rental through a state
 * that doesn't always apply. Instead this records `inspected_at`/
 * `inspection_notes` on the existing `returned` row;
 * `CompleteRentalReservationAction` enforces that a category requiring
 * inspection has one recorded before allowing completion.
 */
class InspectRentalReservationAction
{
    public function execute(int $reservationId, ?string $notes = null): RentalReservation
    {
        return DB::transaction(function () use ($reservationId, $notes) {
            $reservation = RentalReservation::lockForUpdate()->findOrFail($reservationId);

            if ($reservation->status !== 'returned') {
                throw new \RuntimeException("Reservation [{$reservationId}] cannot be inspected from status '{$reservation->status}' (expected 'returned').");
            }

            $reservation->inspected_at = now();
            $reservation->inspection_notes = $notes;
            $reservation->save();

            return $reservation->fresh();
        });
    }
}
