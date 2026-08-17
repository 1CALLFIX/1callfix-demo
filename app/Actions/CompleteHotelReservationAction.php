<?php

namespace App\Actions;

use App\Events\HotelReservationStatusUpdated;
use App\Models\HotelReservation;
use App\Notifications\HotelReservationStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\CommissionService;
use Illuminate\Support\Facades\DB;

/**
 * HOTEL / STAY BOOKING MODULE — checked_out → completed. The administrative
 * settlement step (commission applied here, same placement convention every
 * prior completion action in this codebase uses) — deliberately separate
 * from CheckOutHotelReservationAction's guest-departs moment, per the
 * mission brief's own distinct check-in/check-out lifecycle requirement.
 */
class CompleteHotelReservationAction
{
    public function __construct(private CommissionService $commissionService)
    {
    }

    public function execute(int $reservationId): HotelReservation
    {
        $reservation = DB::transaction(function () use ($reservationId) {
            $reservation = HotelReservation::lockForUpdate()->findOrFail($reservationId);

            if ($reservation->status !== 'checked_out') {
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

            event(new HotelReservationStatusUpdated($reservation));

            return $reservation->fresh();
        });

        $this->commissionService->applyForHotelReservation($reservation);

        if ($reservation->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);
            $reservation->customer->notify(new HotelReservationStatusNotification('completed', $reservation, $channels));
        }

        return $reservation;
    }
}
