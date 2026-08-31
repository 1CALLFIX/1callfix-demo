<?php

namespace App\Services;

use App\Models\Otp;
use App\Models\Setting;
use App\Notifications\EmailOtpNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * The custom EMAIL verification / password-reset OTP engine.
 *
 * ── History ──────────────────────────────────────────────────────────────
 * This started life (OTP_ARCHITECTURE.md) as the shared LOGIN OTP engine
 * for phone numbers, delivering via SmsAdapter. The auth rebuild removed
 * OTP as a login mechanism entirely: phone verification is now done by
 * Firebase (client-side, ID token verified server-side by
 * App\Services\Auth\FirebaseTokenVerifier) and login is password-based.
 * What remains — and what this class now is — is the ONE thing Firebase
 * cannot do for us: deliver a short numeric code to an EMAIL address
 * (Firebase's email flow is a magic link, not a code). It is used for
 * signup email verification and for password reset by email.
 *
 * It is still deliberately NOT the Service booking start/completion OTP —
 * that remains on bookings.start_otp/completion_otp inside
 * Accept/Start/CompleteBookingAction, a separate untouched mechanism.
 *
 * ── Security properties (unchanged from the phone era) ────────────────────
 * Code hashed at rest (never stored plaintext); one active code per
 * (identifier, purpose) at a time (a new request expires any still-pending
 * prior one); attempt-limited with a hard lock after max_attempts;
 * resend-cooldown enforced; every generate/verify is auditable via the
 * otps row itself (ip_address/device_identifier/timestamps).
 */
class OtpService
{
    public const PURPOSE_EMAIL_VERIFY = 'email_verify';

    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    private function otpLength(): int
    {
        return (int) Setting::get('auth.otp_length', 6);
    }

    private function expirySeconds(): int
    {
        return (int) Setting::get('auth.otp_expiry_seconds', 300);
    }

    private function resendCooldownSeconds(): int
    {
        return (int) Setting::get('auth.otp_resend_cooldown_seconds', 30);
    }

    private function maxAttempts(): int
    {
        return (int) Setting::get('auth.otp_max_attempts', 5);
    }

    /**
     * Send a fresh numeric code to $identifier (an email address) for the
     * given $purpose.
     *
     * @throws \RuntimeException if called again before the resend cooldown elapses
     */
    public function generate(string $identifier, string $purpose, ?string $ipAddress = null, ?string $deviceIdentifier = null): Otp
    {
        $recent = Otp::where('identifier', $identifier)->where('purpose', $purpose)
            ->whereNotNull('last_sent_at')
            ->latest('last_sent_at')
            ->first();

        if ($recent && $recent->last_sent_at->diffInSeconds(now()) < $this->resendCooldownSeconds()) {
            $wait = $this->resendCooldownSeconds() - $recent->last_sent_at->diffInSeconds(now());
            throw new \RuntimeException("Please wait {$wait} more second(s) before requesting another code.");
        }

        // Only one PENDING code per (identifier, purpose) at a time — an old
        // still-valid code being usable alongside a freshly requested one is
        // a real (if minor) confusion/security surface.
        Otp::where('identifier', $identifier)->where('purpose', $purpose)
            ->where('status', Otp::STATUS_PENDING)
            ->update(['status' => Otp::STATUS_EXPIRED]);

        $length = $this->otpLength();
        $code = (string) random_int((int) (10 ** ($length - 1)), (int) (10 ** $length) - 1);

        $otp = Otp::create([
            'identifier' => $identifier,
            'code_hash' => Hash::make($code),
            'purpose' => $purpose,
            'channel' => 'email',
            'attempt_count' => 0,
            'max_attempts' => $this->maxAttempts(),
            'status' => Otp::STATUS_PENDING,
            'last_sent_at' => now(),
            'ip_address' => $ipAddress,
            'device_identifier' => $deviceIdentifier,
            'expires_at' => now()->addSeconds($this->expirySeconds()),
        ]);

        // On-demand mail routing — there is not necessarily a User row for
        // this address yet (email-first signup creates the account only
        // AFTER the code is verified). The plaintext code exists only in
        // this method's local scope; it is never persisted or returned to
        // any caller above generate().
        Notification::route('mail', $identifier)
            ->notify(new EmailOtpNotification($code, $purpose, $this->expirySeconds()));

        return $otp;
    }

    /**
     * @return array{success:bool, reason:?string, otp:?Otp}
     */
    public function verify(string $identifier, string $purpose, string $submittedCode): array
    {
        // The latest OTP for this (identifier, purpose) REGARDLESS of status
        // — filtering to pending only was a real bug: once a code locked
        // mid-verification this query could no longer find it, so every
        // later attempt (incl. the correct code) came back 'not_found'
        // instead of the correct 'locked'.
        $otp = Otp::where('identifier', $identifier)->where('purpose', $purpose)
            ->latest('id')
            ->first();

        if (! $otp || $otp->status === Otp::STATUS_VERIFIED) {
            return ['success' => false, 'reason' => 'not_found', 'otp' => null];
        }

        if ($otp->status === Otp::STATUS_LOCKED) {
            return ['success' => false, 'reason' => 'locked', 'otp' => $otp];
        }

        if ($otp->status === Otp::STATUS_EXPIRED) {
            return ['success' => false, 'reason' => 'expired', 'otp' => $otp];
        }

        if ($otp->isExpired()) {
            $otp->update(['status' => Otp::STATUS_EXPIRED]);

            return ['success' => false, 'reason' => 'expired', 'otp' => $otp];
        }

        if (! $otp->hasAttemptsRemaining()) {
            $otp->update(['status' => Otp::STATUS_LOCKED]);

            return ['success' => false, 'reason' => 'locked', 'otp' => $otp];
        }

        if (! Hash::check($submittedCode, $otp->code_hash)) {
            $otp->increment('attempt_count');
            if (! $otp->hasAttemptsRemaining()) {
                $otp->update(['status' => Otp::STATUS_LOCKED]);

                return ['success' => false, 'reason' => 'locked', 'otp' => $otp];
            }

            return ['success' => false, 'reason' => 'invalid', 'otp' => $otp];
        }

        $otp->update(['status' => Otp::STATUS_VERIFIED, 'verified_at' => now()]);

        return ['success' => true, 'reason' => null, 'otp' => $otp];
    }
}
