<?php

namespace App\Actions;

use App\Events\BookingStatusUpdated;
use App\Jobs\BundleConsolidationJob;
use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\Provider;
use App\Models\Setting;
use App\Notifications\BookingOtpNotification;
use App\Notifications\BookingStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\BookingOtpService;
use App\Services\ProviderAvailabilityService;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
     *         already accepted by someone else, or already withdrawn), or
     *         if the provider's wallet balance is below the configured
     *         wallet.provider_min_balance_to_accept_jobs for their scope
     */
    public function execute(int $bookingId, Provider $provider): Booking
    {
        $this->assertMeetsMinimumWalletBalance($provider);

        $booking = DB::transaction(function () use ($bookingId, $provider) {
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

            // Phase E4 — race-safe provider time-slot guard. A pre-dispatch
            // availability check is not enough: two offers for overlapping
            // windows can both go out to the same free provider (bundle
            // consolidation offers one directly; a plain accept/accept race
            // can do it too), and without this recheck BOTH could commit and
            // leave the provider double-booked. Locking the provider row
            // FOR UPDATE serializes every concurrent AcceptBookingAction for
            // the same provider — the second one blocks here until the first
            // commits, then re-reads the now-assigned booking and correctly
            // sees the conflict. Same "lock the parent resource, then
            // re-check inside the caller's transaction" pattern
            // RentalAvailabilityService uses (parent = the Provider here).
            // On MySQL/Postgres this is a genuine row lock; on SQLite the
            // whole-database write lock serializes equivalently.
            Provider::whereKey($provider->id)->lockForUpdate()->firstOrFail();

            $booking->loadMissing('service');
            $durationMinutes = (int) ($booking->service->duration_estimate_mins ?? 0);

            if ($booking->scheduled_at !== null
                && ! app(ProviderAvailabilityService::class)->isAvailableAt(
                    $provider, $booking->scheduled_at, $durationMinutes, $booking->id)) {
                throw new \RuntimeException(
                    'You already have another job scheduled that overlaps this one\'s time slot.');
            }

            // Assign. OTP length is admin-editable via Settings (default 4,
            // same as before that screen existed) — see Booking Settings.
            $otpLength = (int) Setting::get('booking.otp_length', 4);
            $otpMin = (int) (10 ** ($otpLength - 1));
            $otpMax = (int) (10 ** $otpLength) - 1;

            $booking->provider_id = $provider->id;
            $booking->status = 'assigned';
            $booking->start_otp = (string) random_int($otpMin, $otpMax);
            $booking->completion_otp = (string) random_int($otpMin, $otpMax);
            // Phase E5 — stamp expiry + reset the attempt / single-use
            // metadata for both freshly generated codes, in this same save.
            app(BookingOtpService::class)->stampFresh($booking);
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

        if ($booking->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);

            // Same isolation as the OTP sends below, and for the same
            // reason: this call goes through the identical channel/adapter
            // pipeline (SmsChannel/PushChannel -> the bound SmsAdapter/
            // PushAdapter), so a gateway outage here is just as real a
            // possibility as it is for the OTPs. It was previously sent
            // bare, so a transport failure on THIS call alone would have
            // thrown before either OTP send below ever ran — turning a
            // delivery hiccup into a fully broken (uncaught exception)
            // acceptance despite the booking itself already being
            // committed. Guarded the same way: outside the transaction,
            // individually try/caught, failure only logged.
            $this->sendStatusNotification($booking, 'assigned', $channels);

            // Closes the gap AUTH_FORENSIC_DISCOVERY.md found: both codes
            // were generated right above but never actually delivered to
            // the customer by any channel. Sent here — not inside
            // StartBookingAction — because that step is explicitly
            // optional for Provider self-completion (see its own
            // docblock); acceptance is the one point BOTH the self-perform
            // and Worker-delegation paths always pass through, so this is
            // the only place that guarantees delivery either way. Outside
            // the transaction above (booking is already committed), and
            // individually try/caught so a notification failure can never
            // roll back or corrupt the already-successful acceptance —
            // the OTPs remain fully valid and verifiable even if this send
            // fails; the failure is only logged, never silently swallowed.
            $this->sendOtpNotification($booking, 'start', $booking->start_otp, $channels);
            $this->sendOtpNotification($booking, 'completion', $booking->completion_otp, $channels);
        }

        // Phase E4 — if this booking is a bundle child, try to give the same
        // provider its still-unassigned siblings before they go through a
        // fresh standard dispatch round. Fire-and-forget, after commit: the
        // job re-checks skill / radius / availability / dispatchability for
        // each sibling and falls back to ServiceMatchingJob on any miss, so
        // nothing here can strand a sibling or affect this acceptance.
        if ($booking->booking_bundle_id !== null) {
            BundleConsolidationJob::dispatch($booking->id);
        }

        return $booking;
    }

    private function sendStatusNotification(Booking $booking, string $event, array $channels): void
    {
        try {
            $booking->customer->notify(new BookingStatusNotification($event, $booking, $channels));
        } catch (\Throwable $e) {
            Log::error("Failed to deliver booking {$event} status notification for booking [{$booking->id}]: ".$e->getMessage());
        }
    }

    private function sendOtpNotification(Booking $booking, string $type, string $code, array $channels): void
    {
        try {
            $booking->customer->notify(new BookingOtpNotification($booking, $type, $code, $channels));
        } catch (\Throwable $e) {
            Log::error("Failed to deliver booking {$type} OTP notification for booking [{$booking->id}]: ".$e->getMessage());
        }
    }

    /**
     * A credit-worthiness gate, not a payment — the provider's own wallet
     * balance (topped up from commission earnings) has to stay above this
     * floor to keep accepting new jobs. 0 (the default) means no gate.
     * Checked before the transaction starts — this isn't part of the
     * booking's own atomicity, just an eligibility pre-check.
     */
    private function assertMeetsMinimumWalletBalance(Provider $provider): void
    {
        $scope = array_filter([
            'zone_id' => $provider->zone_id,
            'franchise_id' => $provider->franchise_id,
            'city_id' => $provider->franchise?->city_id,
            'country_id' => $provider->franchise?->country_id,
        ]);

        $minBalance = (float) Setting::get('wallet.provider_min_balance_to_accept_jobs', '0', $scope);

        if ($minBalance <= 0) {
            return;
        }

        $balance = app(WalletService::class)->balance($provider->user);

        if ($balance < $minBalance) {
            throw new \RuntimeException("Your wallet balance ({$balance}) is below the minimum ({$minBalance}) required to accept new jobs.");
        }
    }
}
