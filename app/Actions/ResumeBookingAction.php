<?php

namespace App\Actions;

use App\Events\BookingStatusUpdated;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;

class ResumeBookingAction
{
    /**
     * Resumes a held booking back to in_progress and clears the hold fields.
     * The hold history itself isn't lost — it's preserved in
     * booking_status_history, this just clears the "currently on hold" state.
     *
     * @throws \RuntimeException if the booking isn't currently on hold
     */
    public function execute(int $bookingId, ?string $resolutionNote = null): Booking
    {
        return DB::transaction(function () use ($bookingId, $resolutionNote) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if ($booking->status !== 'on_hold') {
                throw new \RuntimeException("Booking [{$bookingId}] is not currently on hold.");
            }

            $previousReason = $booking->hold_reason;

            $booking->status = 'in_progress';
            $booking->hold_category = null;
            $booking->hold_reason = null;
            $booking->hold_note = null;
            $booking->on_hold_since = null;
            $booking->save();

            $booking->statusHistory()->create([
                'status' => 'in_progress',
                'changed_by' => $booking->provider?->user_id,
                'note' => "Resumed from hold (was: {$previousReason})" .
                    ($resolutionNote ? " — {$resolutionNote}" : ''),
                'changed_at' => now(),
            ]);

            event(new BookingStatusUpdated($booking));

            return $booking->fresh();
        });
    }
}
