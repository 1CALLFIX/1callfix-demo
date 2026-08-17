<?php

namespace App\Actions;

use App\Events\RentalReservationStatusUpdated;
use App\Models\RentalReservation;
use App\Notifications\RentalReservationStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\CancellationService;
use Illuminate\Support\Facades\DB;

/**
 * RENTAL MODULE IMPLEMENTATION -- the shared Vehicle/Equipment engine
 * counterpart to AdminCancelPropertyReservationAction. No separate
 * "release the item" step is needed here (unlike Property's
 * PropertyAvailabilityService::releaseDates()) -- RentalAvailabilityService's
 * own overlap check already excludes `status = 'cancelled'` reservations,
 * so cancelling immediately frees the range for a new reservation with no
 * further bookkeeping (see RentalAvailabilityService's own docblock).
 */
class AdminCancelRentalReservationAction
{
    public function __construct(private CancellationService $cancellationService)
    {
    }

    public function execute(int $reservationId, string $reason): RentalReservation
    {
        $reservation = DB::transaction(function () use ($reservationId, $reason) {
            $reservation = RentalReservation::lockForUpdate()->findOrFail($reservationId);

            if (in_array($reservation->status, ['completed', 'cancelled'], true)) {
                throw new \RuntimeException("Reservation is already {$reservation->status}, cannot cancel.");
            }

            $fee = $this->cancellationService->calculateFeeForRentalReservation($reservation);

            $reservation->status = 'cancelled';
            $reservation->cancellation_note = $reason;
            $reservation->cancellation_fee = $fee;
            $reservation->save();

            $reservation->statusHistory()->create([
                'status' => 'cancelled',
                'note' => "Cancelled by admin: {$reason}".($fee > 0 ? " (cancellation fee: {$fee})" : ''),
                'changed_at' => now(),
            ]);

            event(new RentalReservationStatusUpdated($reservation));

            return $reservation->fresh();
        });

        $this->cancellationService->refundIfPaidForRentalReservation($reservation, (float) $reservation->cancellation_fee);

        if ($reservation->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);
            $reservation->customer->notify(new RentalReservationStatusNotification('cancelled', $reservation, $channels));
        }

        return $reservation->fresh();
    }
}
