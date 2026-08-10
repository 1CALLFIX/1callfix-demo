<?php

namespace App\Actions;

use App\Events\BookingStatusUpdated;
use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\Provider;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class AdminReassignBookingAction
{
    /**
     * Admin manually assigns (or reassigns) a booking to a specific
     * provider — used from the admin panel's live queue when auto-dispatch
     * hasn't found anyone, or when a booking needs to move to a different
     * provider mid-flow. Bypasses the normal dispatch_attempts offer/accept
     * cycle since the admin is making the call directly, not the provider.
     */
    public function execute(int $bookingId, int $providerId, string $adminNote = ''): Booking
    {
        return DB::transaction(function () use ($bookingId, $providerId, $adminNote) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);
            $provider = Provider::findOrFail($providerId);

            $previousProviderId = $booking->provider_id;

            $booking->provider_id = $provider->id;
            if (in_array($booking->status, ['pending', 'searching_provider'], true)) {
                // Same admin-editable OTP length as AcceptBookingAction.
                $otpLength = (int) Setting::get('booking.otp_length', 4);
                $otpMin = (int) (10 ** ($otpLength - 1));
                $otpMax = (int) (10 ** $otpLength) - 1;

                $booking->status = 'assigned';
                $booking->start_otp = $booking->start_otp ?: (string) random_int($otpMin, $otpMax);
                $booking->completion_otp = $booking->completion_otp ?: (string) random_int($otpMin, $otpMax);
            }
            $booking->save();

            DispatchAttempt::where('booking_id', $bookingId)
                ->where('status', 'notified')
                ->update(['status' => 'timeout', 'responded_at' => now()]);

            $note = $previousProviderId
                ? "Admin reassigned from provider #{$previousProviderId} to #{$provider->id}"
                : "Admin manually assigned to provider #{$provider->id}";
            if ($adminNote) {
                $note .= " — {$adminNote}";
            }

            $booking->statusHistory()->create([
                'status' => $booking->status,
                'note' => $note,
                'changed_at' => now(),
            ]);

            event(new BookingStatusUpdated($booking));

            return $booking->fresh();
        });
    }
}
