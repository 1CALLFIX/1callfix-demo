<?php

namespace App\Actions;

use App\Events\BookingStatusUpdated;
use App\Models\Booking;
use App\Models\Provider;
use App\Services\CommissionService;
use Illuminate\Support\Facades\DB;

class CompleteBookingAction
{
    public function __construct(private CommissionService $commissionService)
    {
    }

    /**
     * Provider marks the job complete, entering the OTP the customer read
     * out to them (start_otp confirms the provider actually arrived and
     * began work — this uses a separate completion_otp shown to the
     * customer once the provider signals they're done, so the customer
     * confirms the work actually happened before it's marked complete).
     *
     * On success: status -> completed, commission split calculated and
     * applied, provider's wallet credited.
     *
     * @throws \RuntimeException if the OTP is wrong or the booking isn't
     *         in a completable state
     */
    public function execute(int $bookingId, Provider $provider, string $enteredOtp): Booking
    {
        $booking = DB::transaction(function () use ($bookingId, $provider, $enteredOtp) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if ($booking->provider_id !== $provider->id) {
                throw new \RuntimeException('This booking is not assigned to you.');
            }

            if (!in_array($booking->status, ['assigned', 'provider_en_route', 'in_progress'], true)) {
                throw new \RuntimeException(
                    "Booking [{$bookingId}] cannot be completed from status '{$booking->status}'."
                );
            }

            if (empty($booking->completion_otp) || $booking->completion_otp !== $enteredOtp) {
                throw new \RuntimeException('Incorrect completion OTP.');
            }

            $booking->status = 'completed';
            $booking->price_final = $booking->price_final ?? $booking->price_quoted;
            $booking->completed_at = now();
            $booking->save();

            $booking->statusHistory()->create([
                'status' => 'completed',
                'changed_by' => $provider->user_id,
                'note' => 'Completed with verified OTP',
                'changed_at' => now(),
            ]);

            $provider->increment('jobs_completed');

            event(new BookingStatusUpdated($booking));

            return $booking->fresh();
        });

        // Commission split runs after the completion transaction commits —
        // deliberately outside the lock above, since it does its own
        // transaction and wallet crediting, and we don't want to hold the
        // booking row lock any longer than necessary.
        $this->commissionService->applyForBooking($booking);

        return $booking;
    }
}
