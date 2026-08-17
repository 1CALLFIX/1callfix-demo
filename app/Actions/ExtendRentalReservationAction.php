<?php

namespace App\Actions;

use App\Models\RentalReservation;
use App\Services\RentalAvailabilityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * RENTAL MODULE IMPLEMENTATION -- the shared "extension" capability the
 * brief lists (Property Rental has no equivalent today -- extending a
 * stay isn't built for it either; this is new capability for the two new
 * rental types only). Only valid while a reservation is `active` (the
 * item is genuinely out with the customer already) -- extending a
 * not-yet-started or already-finished reservation isn't a real scenario
 * this method supports.
 *
 * Re-runs the exact same `RentalAvailabilityService::reserveRange()` lock
 * +overlap check as creation, excluding the reservation's own row, so an
 * extension that would collide with a DIFFERENT reservation for the same
 * item is rejected with the same concurrency-safety guarantee as creation.
 */
class ExtendRentalReservationAction
{
    public function __construct(private RentalAvailabilityService $availability)
    {
    }

    public function execute(int $reservationId, string $newEndsAt): RentalReservation
    {
        return DB::transaction(function () use ($reservationId, $newEndsAt) {
            $reservation = RentalReservation::lockForUpdate()->findOrFail($reservationId);

            if ($reservation->status !== 'active') {
                throw new \RuntimeException("Reservation [{$reservationId}] cannot be extended from status '{$reservation->status}' (expected 'active').");
            }

            $newEnd = Carbon::parse($newEndsAt);
            if (! $newEnd->gt($reservation->ends_at)) {
                throw new \RuntimeException('The new end time must be after the current end time.');
            }

            $rentable = $reservation->rentable()->firstOrFail();

            // Re-check availability for the extended range, excluding this
            // reservation's own row -- see class docblock.
            $this->availability->reserveRange($rentable, $reservation->starts_at, $newEnd, $reservation->id);

            $unitPrice = $rentable->discount_price > 0 ? (float) $rentable->discount_price : (float) $rentable->base_price;
            $newDurationQuantity = RentalReservation::computeDurationQuantity($reservation->starts_at, $newEnd, $reservation->duration_unit);
            $additionalQuantity = max(0, $newDurationQuantity - (float) $reservation->duration_quantity);

            $reservation->ends_at = $newEnd;
            $reservation->duration_quantity = $newDurationQuantity;
            $reservation->price_quoted = round((float) $reservation->price_quoted + ($unitPrice * $additionalQuantity), 2);
            $reservation->save();

            $reservation->statusHistory()->create([
                'status' => 'active',
                'note' => "Extended to {$newEnd->toDateTimeString()}",
                'changed_at' => now(),
            ]);

            return $reservation->fresh();
        });
    }
}
