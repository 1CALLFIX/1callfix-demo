<?php

namespace App\Actions;

use App\Events\PropertyReservationStatusUpdated;
use App\Models\PropertyReservation;
use App\Notifications\PropertyReservationStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\CancellationService;
use App\Services\PropertyAvailabilityService;
use Illuminate\Support\Facades\DB;

/**
 * Phase 22.7 (Property Rental) — the PropertyReservation counterpart to
 * AdminCancelParcelOrderAction/AdminCancelTaxiRideAction, with one real
 * difference neither of those needed: cancelling a reservation must also
 * release its held dates (`PropertyAvailabilityService::releaseDates()`)
 * back to available, inside the SAME transaction as the status change —
 * otherwise a cancelled reservation would permanently lock out its dates.
 */
class AdminCancelPropertyReservationAction
{
    public function __construct(
        private CancellationService $cancellationService,
        private PropertyAvailabilityService $availability,
    ) {
    }

    public function execute(int $reservationId, string $reason): PropertyReservation
    {
        $reservation = DB::transaction(function () use ($reservationId, $reason) {
            $reservation = PropertyReservation::lockForUpdate()->findOrFail($reservationId);

            if (in_array($reservation->status, ['completed', 'cancelled'], true)) {
                throw new \RuntimeException("Reservation is already {$reservation->status}, cannot cancel.");
            }

            $fee = $this->cancellationService->calculateFeeForPropertyReservation($reservation);

            $reservation->status = 'cancelled';
            $reservation->cancellation_note = $reason;
            $reservation->cancellation_fee = $fee;
            $reservation->save();

            $this->availability->releaseDates($reservation);

            $reservation->statusHistory()->create([
                'status' => 'cancelled',
                'note' => "Cancelled by admin: {$reason}".($fee > 0 ? " (cancellation fee: {$fee})" : ''),
                'changed_at' => now(),
            ]);

            event(new PropertyReservationStatusUpdated($reservation));

            return $reservation->fresh();
        });

        $this->cancellationService->refundIfPaidForPropertyReservation($reservation, (float) $reservation->cancellation_fee);

        if ($reservation->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);
            $reservation->customer->notify(new PropertyReservationStatusNotification('cancelled', $reservation, $channels));
        }

        return $reservation->fresh();
    }
}
