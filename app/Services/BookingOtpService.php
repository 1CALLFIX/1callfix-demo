<?php

namespace App\Services;

use App\Exceptions\BookingOtpException;
use App\Models\Booking;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase E5 — the hardening layer around the EXISTING Service booking OTP
 * (bookings.start_otp / completion_otp). It does not change how those codes
 * are generated (AcceptBookingAction / AdminReassignBookingAction still
 * random_int() them, admin-editable length via booking.otp_length) or where
 * they live (the booking row — OTP_ARCHITECTURE.md "Option C"). It adds
 * exactly the three properties that doc recorded as missing and "carried
 * forward as a known gap, not invented as a fix without approval":
 *
 *   - expiry ......... {kind}_otp_expires_at, checked on verify. NULL means
 *                      "no expiry" (every booking created before E5), so the
 *                      check is purely additive.
 *   - attempt cap .... {kind}_otp_attempts vs booking.otp_max_attempts
 *                      (Setting, default 5 — the same default the shared
 *                      login engine uses). A wrong code increments it; at the
 *                      cap every further attempt is refused until the code is
 *                      regenerated (AdminReassignBookingAction).
 *   - single use ..... a successful verify NULLs the code and stamps
 *                      {kind}_otp_verified_at, so the same code can never be
 *                      replayed — even if the booking is later forced back to
 *                      a startable / completable status by some other path.
 *
 * The plain-string comparison, and the "wrong code -> RuntimeException,
 * retry allowed, booking NOT cancelled" contract the FSM tests and the
 * mission's own instruction depend on, are unchanged.
 */
class BookingOtpService
{
    /** @var list<'start'|'completion'> */
    private const KINDS = ['start', 'completion'];

    private function scopeFor(Booking $booking): array
    {
        return array_filter([
            'zone_id' => $booking->zone_id,
            'franchise_id' => $booking->franchise_id,
        ]);
    }

    private function ttlMinutes(Booking $booking): int
    {
        return max(1, (int) Setting::get('booking.otp_ttl_minutes', '1440', $this->scopeFor($booking)));
    }

    private function maxAttempts(Booking $booking): int
    {
        return max(1, (int) Setting::get('booking.otp_max_attempts', '5', $this->scopeFor($booking)));
    }

    /**
     * Stamp expiry and reset the attempt / single-use metadata for one or
     * both freshly generated codes. Call this in the SAME unit of work as
     * the random_int() assignment, before the booking is saved. Expiry runs
     * from the scheduled start when there is one (so a booking scheduled for
     * next week still gets a usable code) or from now for an instant
     * booking, plus the configured TTL.
     *
     * @param  'start'|'completion'|null  $kind  null = both
     */
    public function stampFresh(Booking $booking, ?string $kind = null): void
    {
        $base = $booking->scheduled_at ? Carbon::parse($booking->scheduled_at) : Carbon::now();
        $expiry = $base->copy()->addMinutes($this->ttlMinutes($booking));

        foreach ($kind === null ? self::KINDS : [$kind] as $k) {
            $booking->{"{$k}_otp_expires_at"} = $expiry;
            $booking->{"{$k}_otp_attempts"} = 0;
            $booking->{"{$k}_otp_verified_at"} = null;
        }
    }

    /**
     * Verify an entered code for $kind ('start' | 'completion') against the
     * booking's own column, enforcing expiry / attempt-cap / single-use.
     *
     * Call it inside the caller's DB::transaction on a lockForUpdate()'d
     * $booking. On success it mutates and saves the booking (code NULLed,
     * verified_at stamped, attempts reset) so the consume is atomic with
     * the transition the caller is about to write.
     *
     * On a plain WRONG code it throws BookingOtpException with
     * countsAsAttempt = true but does NOT itself persist the increment —
     * the caller's transaction is about to roll back, so the caller must
     * call registerFailedAttempt() afterwards, outside that transaction.
     * Expired / already-used / cap-exhausted throw with countsAsAttempt =
     * false (nothing to count).
     *
     * @throws BookingOtpException if the code does not verify — the message
     *         contains "Incorrect {kind} OTP" for a wrong code (the
     *         unchanged contract), else a specific reason.
     */
    public function verifyOrFail(Booking $booking, string $kind, string $entered): void
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("Unknown booking OTP kind [{$kind}].");
        }

        $label = "{$kind} OTP";
        $code = $booking->{"{$kind}_otp"};
        $verifiedAt = $booking->{"{$kind}_otp_verified_at"};
        $expiresAt = $booking->{"{$kind}_otp_expires_at"};
        $attempts = (int) $booking->{"{$kind}_otp_attempts"};

        // Consumed already: a successful verify NULLs the code and stamps
        // verified_at together, so either being set means "replay".
        if ($verifiedAt !== null || $code === null || $code === '') {
            throw new BookingOtpException("This {$label} has already been used.");
        }

        if ($expiresAt !== null && Carbon::parse($expiresAt)->isPast()) {
            throw new BookingOtpException("This {$label} has expired.");
        }

        if ($attempts >= $this->maxAttempts($booking)) {
            throw new BookingOtpException("Too many incorrect {$label} attempts. Ask an admin to reissue the code.");
        }

        if (! hash_equals((string) $code, $entered)) {
            throw new BookingOtpException("Incorrect {$label}.", countsAsAttempt: true);
        }

        $booking->{"{$kind}_otp"} = null;
        $booking->{"{$kind}_otp_verified_at"} = now();
        $booking->{"{$kind}_otp_attempts"} = 0;
        $booking->save();
    }

    /**
     * Commit a single failed-attempt increment for $kind on its own,
     * independent of any transaction the caller may have just rolled back.
     * Capped at the configured maximum so the column can never run away.
     */
    public function registerFailedAttempt(int $bookingId, string $kind): void
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("Unknown booking OTP kind [{$kind}].");
        }

        DB::transaction(function () use ($bookingId, $kind) {
            $booking = Booking::lockForUpdate()->find($bookingId);
            if (! $booking) {
                return;
            }

            $column = "{$kind}_otp_attempts";
            $booking->{$column} = min($this->maxAttempts($booking), (int) $booking->{$column} + 1);
            $booking->save();
        });
    }
}
