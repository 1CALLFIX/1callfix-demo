<?php

namespace App\Actions;

use App\Models\Booking;
use App\Models\BookingCallAttempt;
use App\Models\Provider;

/** REF 1CF-CANCEL-POLICY-001 scenario 5 — one in-app call attempt to the customer, logged after a verified arrival. */
class LogCallAttemptAction
{
    /**
     * @return int the number of attempts logged so far
     *
     * @throws \RuntimeException
     */
    public function execute(int $bookingId, Provider $provider): int
    {
        $booking = Booking::findOrFail($bookingId);

        if ($booking->provider_id !== $provider->id) {
            throw new \RuntimeException('This booking is not assigned to you.');
        }
        if ($booking->status !== 'provider_en_route' || $booking->arrival_verified_at === null) {
            throw new \RuntimeException('Check in at the address before logging call attempts.');
        }

        BookingCallAttempt::create(['booking_id' => $booking->id, 'provider_id' => $provider->id, 'attempted_at' => now()]);

        return $booking->callAttempts()->count();
    }
}
