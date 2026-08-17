<?php

namespace App\Actions;

use App\Events\HotelReservationStatusUpdated;
use App\Models\HotelReservation;
use App\Notifications\HotelReservationStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\CancellationService;
use App\Services\HotelAvailabilityService;
use Illuminate\Support\Facades\DB;

/**
 * HOTEL / STAY BOOKING MODULE — the HotelReservation counterpart to
 * AdminCancelPropertyReservationAction, extended to release EVERY room
 * line's held inventory (not just one): a multi-room reservation must give
 * back every line's rooms_booked count across the whole date range, inside
 * the SAME transaction as the status change, exactly the same
 * "cancellation must not permanently lock out inventory" guarantee
 * Property Rental's own cancellation already gives.
 */
class AdminCancelHotelReservationAction
{
    public function __construct(
        private CancellationService $cancellationService,
        private HotelAvailabilityService $availability,
    ) {
    }

    public function execute(int $reservationId, string $reason): HotelReservation
    {
        $reservation = DB::transaction(function () use ($reservationId, $reason) {
            $reservation = HotelReservation::with('rooms.roomType')->lockForUpdate()->findOrFail($reservationId);

            if (in_array($reservation->status, ['checked_out', 'completed', 'cancelled'], true)) {
                throw new \RuntimeException("Reservation is already {$reservation->status}, cannot cancel.");
            }

            $fee = $this->cancellationService->calculateFeeForHotelReservation($reservation);

            $reservation->status = 'cancelled';
            $reservation->cancellation_note = $reason;
            $reservation->cancellation_fee = $fee;
            $reservation->save();

            foreach ($reservation->rooms as $line) {
                $this->availability->releaseRooms(
                    $line->roomType,
                    $reservation->check_in_date->toDateString(),
                    $reservation->check_out_date->toDateString(),
                    $line->room_count
                );
            }

            $reservation->statusHistory()->create([
                'status' => 'cancelled',
                'note' => "Cancelled by admin: {$reason}".($fee > 0 ? " (cancellation fee: {$fee})" : ''),
                'changed_at' => now(),
            ]);

            event(new HotelReservationStatusUpdated($reservation));

            return $reservation->fresh();
        });

        $this->cancellationService->refundIfPaidForHotelReservation($reservation, (float) $reservation->cancellation_fee);

        if ($reservation->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);
            $reservation->customer->notify(new HotelReservationStatusNotification('cancelled', $reservation, $channels));
        }

        return $reservation->fresh();
    }
}
