<?php

namespace App\Services;

use App\Contracts\SmsAdapter;
use App\Models\Otp;
use App\Models\Setting;
use App\Notifications\OtpNotification;
use Illuminate\Support\Facades\Hash;

/**
 * The shared LOGIN/verification OTP engine — see OTP_ARCHITECTURE.md for
 * the Option A/B/C analysis behind why this is separate from, and does
 * NOT touch, the working Service booking start/completion OTP
 * (bookings.start_otp/completion_otp, still generated/verified entirely
 * inside AcceptBookingAction/StartBookingAction/CompleteBookingAction,
 * unmodified by this class).
 *
 * Security properties: code is hashed at rest (never stored plaintext),
 * one active OTP per (phone, purpose) at a time (generating a new one
 * invalidates any still-pending prior one), attempt-limited with lockout,
 * resend-cooldown enforced, every generate/verify call is auditable via
 * the otps row itself (ip_address/device_identifier/timestamps).
 */
class OtpService
{
    public function __construct(private SmsAdapter $smsAdapter)
    {
    }

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
     * @throws \RuntimeException if called again before the resend cooldown elapses
     */
    public function generate(string $phone, string $purpose, ?string $ipAddress = null, ?string $deviceIdentifier = null): Otp
    {
        $recent = Otp::where('phone', $phone)->where('purpose', $purpose)
            ->whereNotNull('last_sent_at')
            ->latest('last_sent_at')
            ->first();

        if ($recent && $recent->last_sent_at->diffInSeconds(now()) < $this->resendCooldownSeconds()) {
            $wait = $this->resendCooldownSeconds() - $recent->last_sent_at->diffInSeconds(now());
            throw new \RuntimeException("Please wait {$wait} more second(s) before requesting another code.");
        }

        // Only one PENDING code per (phone, purpose) at a time -- an old
        // still-valid code being usable alongside a freshly requested one
        // is a real (if minor) confusion/security surface, not just a UX
        // nicety.
        Otp::where('phone', $phone)->where('purpose', $purpose)
            ->where('status', Otp::STATUS_PENDING)
            ->update(['status' => Otp::STATUS_EXPIRED]);

        $length = $this->otpLength();
        $code = (string) random_int((int) (10 ** ($length - 1)), (int) (10 ** $length) - 1);

        // OTP delivery is SMS, deliberately NOT resolved through the
        // generic notifications.channels Setting (that governs booking
        // status/marketing copy and defaults to 'mail' — useless here,
        // since a phone-only login has no email address to send to). OTP
        // channel selection is its own concern, not the same one
        // ChannelResolver was built for.
        $otp = Otp::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'purpose' => $purpose,
            'channel' => 'sms',
            'attempt_count' => 0,
            'max_attempts' => $this->maxAttempts(),
            'status' => Otp::STATUS_PENDING,
            'last_sent_at' => now(),
            'ip_address' => $ipAddress,
            'device_identifier' => $deviceIdentifier,
            'expires_at' => now()->addSeconds($this->expirySeconds()),
        ]);

        // Direct adapter call, not Laravel's Notification::route() ad-hoc
        // routing -- verified against this app's actual SmsChannel this
        // session: it requires the notifiable to expose either
        // routeNotificationForSms() or a ->phone property, and Laravel's
        // built-in AnonymousNotifiable (what ->route() returns) has
        // neither, so an ad-hoc-routed Notification would silently fail to
        // send here. Calling the SAME SmsAdapter binding directly (still
        // swappable for a real provider exactly the same way, still routes
        // through OtpNotification's message copy so it stays one place)
        // avoids that framework edge case. The plaintext code exists only
        // transiently in this method's local scope -- never persisted,
        // never returned to any caller above generate().
        $this->smsAdapter->send($phone, OtpNotification::smsBody($code, $purpose, $this->expirySeconds()));

        return $otp;
    }

    /**
     * @return array{success:bool, reason:?string, otp:?Otp}
     */
    public function verify(string $phone, string $purpose, string $submittedCode): array
    {
        // The latest OTP for this (phone, purpose) REGARDLESS of status —
        // not status=pending only. Filtering to pending here was a real
        // bug (found via testing): once a code got locked mid-verification,
        // this query could no longer find it at all, so every subsequent
        // attempt (including the correct code, after lockout) came back
        // 'not_found' instead of 'locked' — the wrong, less informative
        // response, and a coincidentally-harmless but incorrect one.
        $otp = Otp::where('phone', $phone)->where('purpose', $purpose)
            ->latest('id')
            ->first();

        if (! $otp || $otp->status === Otp::STATUS_VERIFIED) {
            // A verified (already-consumed) code and a genuinely never-
            // requested code both correctly present as "nothing to verify
            // here" — the caller must request a fresh one either way.
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
