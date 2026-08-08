<?php

namespace App\Actions;

use App\Events\BookingStatusUpdated;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;

class AdminCancelBookingAction
{
    public function execute(int $bookingId, string $reason): Booking
    {
        return DB::transaction(function () use ($bookingId, $reason) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if (in_array($booking->status, ['completed', 'cancelled'], true)) {
                throw new \RuntimeException("Booking is already {$booking->status}, cannot cancel.");
            }

            $booking->status = 'cancelled';
            $booking->cancellation_note = $reason;
            $booking->save();

            $booking->statusHistory()->create([
                'status' => 'cancelled',
                'note' => "Cancelled by admin: {$reason}",
                'changed_at' => now(),
            ]);

            event(new BookingStatusUpdated($booking));

            return $booking->fresh();
        });
    }
}
