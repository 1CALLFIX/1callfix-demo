<?php

namespace App\Actions;

use App\Events\BookingStatusUpdated;
use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\Provider;
use Illuminate\Support\Facades\DB;

class AcceptBookingAction
{
    /**
     * A provider accepts an offered job.
     *
     * Wrapped in a DB transaction with row locking so that if two providers
     * somehow tap "Accept" on the same offer in the same instant (a real
     * possibility since offers go out to up to 5 providers at once), only
     * one of them wins — the other gets a clear "already assigned" failure
     * instead of both silently succeeding and corrupting the booking.
     *
     * @throws \RuntimeException if the offer is no longer valid (expired,
     *         already accepted by someone else, or already withdrawn)
     */
    public function execute(int $bookingId, Provider $provider): Booking
    {
        return DB::transaction(function () use ($bookingId, $provider) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if ($booking->provider_id !== null) {
                throw new \RuntimeException('This job has already been assigned to another provider.');
            }

            $attempt = DispatchAttempt::where('booking_id', $bookingId)
                ->where('provider_id', $provider->id)
                ->where('status', 'notified')
                ->first();

            if (!$attempt) {
                throw new \RuntimeException('This job offer is no longer available (expired or already withdrawn).');
            }

            // Assign
            $booking->provider_id = $provider->id;
            $booking->status = 'assigned';
            $booking->start_otp = (string) random_int(1000, 9999);
            $booking->completion_otp = (string) random_int(1000, 9999);
            $booking->save();

            $attempt->status = 'accepted';
            $attempt->responded_at = now();
            $attempt->save();

            // Every other still-pending offer for this booking is now moot.
            DispatchAttempt::where('booking_id', $bookingId)
                ->where('id', '!=', $attempt->id)
                ->where('status', 'notified')
                ->update(['status' => 'timeout', 'responded_at' => now()]);

            $booking->statusHistory()->create([
                'status' => 'assigned',
                'changed_by' => $provider->user_id,
                'note' => "Accepted by provider #{$provider->id}",
                'changed_at' => now(),
            ]);

            event(new BookingStatusUpdated($booking));

            return $booking->fresh();
        });
    }
}
