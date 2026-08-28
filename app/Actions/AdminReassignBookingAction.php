<?php

namespace App\Actions;

use App\Events\BookingStatusUpdated;
use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\Provider;
use App\Models\Setting;
use App\Notifications\BookingOtpNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\BookingOtpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        $freshlyGeneratedOtps = false;

        $booking = DB::transaction(function () use ($bookingId, $providerId, $adminNote, &$freshlyGeneratedOtps) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);
            $provider = Provider::findOrFail($providerId);

            $previousProviderId = $booking->provider_id;

            $booking->provider_id = $provider->id;
            // Phase B0.2: a worker assignment made under the OLD provider
            // doesn't carry over to a different one — avoids a stale
            // assigned_worker_id pointing at a worker who isn't even on
            // the new provider's team. No-op when reassigning to the same
            // provider (existing admin reassignment behavior otherwise
            // unchanged).
            if ($previousProviderId !== $provider->id) {
                $booking->assigned_worker_id = null;
            }
            if (in_array($booking->status, ['pending', 'searching_provider'], true)) {
                // Same admin-editable OTP length as AcceptBookingAction.
                $otpLength = (int) Setting::get('booking.otp_length', 4);
                $otpMin = (int) (10 ** ($otpLength - 1));
                $otpMax = (int) (10 ** $otpLength) - 1;

                $booking->status = 'assigned';
                // ?: only fills a value that isn't already set — the flag
                // below tracks whether THIS call is what generated it (a
                // reassignment onto an already-OTP'd booking must not
                // re-notify the customer with a code they already have).
                if (! $booking->start_otp) {
                    $booking->start_otp = (string) random_int($otpMin, $otpMax);
                    // Phase E5 — same expiry / attempt / single-use stamp
                    // AcceptBookingAction applies to a freshly issued code.
                    app(BookingOtpService::class)->stampFresh($booking, 'start');
                    $freshlyGeneratedOtps = true;
                }
                if (! $booking->completion_otp) {
                    $booking->completion_otp = (string) random_int($otpMin, $otpMax);
                    app(BookingOtpService::class)->stampFresh($booking, 'completion');
                    $freshlyGeneratedOtps = true;
                }
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

        // Same delivery this session added to AcceptBookingAction's own
        // OTP generation — this action is a separate, legitimate path to
        // a booking's FIRST OTP assignment (the admin live-queue manual-
        // assign case), so it needs the same customer delivery, gated to
        // only fire when this call is what actually generated the codes.
        if ($freshlyGeneratedOtps && $booking->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);
            $this->sendOtpNotification($booking, 'start', $booking->start_otp, $channels);
            $this->sendOtpNotification($booking, 'completion', $booking->completion_otp, $channels);
        }

        return $booking;
    }

    private function sendOtpNotification(Booking $booking, string $type, string $code, array $channels): void
    {
        try {
            $booking->customer->notify(new BookingOtpNotification($booking, $type, $code, $channels));
        } catch (\Throwable $e) {
            Log::error("Failed to deliver booking {$type} OTP notification for booking [{$booking->id}]: ".$e->getMessage());
        }
    }
}
