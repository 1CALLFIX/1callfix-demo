<?php

namespace App\Services\Referrals;

use App\Models\User;
use App\Support\EarningsSettings;
use App\Support\PhoneNumber;
use Illuminate\Support\Str;

/**
 * REF 1CF-PROMPT-20260925-EARN4 — Stage 2. The ONE place a referral code
 * typed at signup is judged, for every surface (web Signup, web Google, API
 * register / Firebase phone / Google).
 *
 *   - referral.enabled OFF (or unset) → codes are not captured at all (R4).
 *     Read GLOBALLY: a signing-up customer has no franchise/zone yet.
 *   - A code is VALID only if it belongs to an active customer who is not
 *     blocked from the referral program (R8) and is not the person signing
 *     up (self-referral, matched on phone) — R3. Every invalid case gets the
 *     same message, so the check can't be used to probe who is blocked.
 *   - A referrer at referral.max_per_customer is still VALID (R3): the
 *     referral is recorded and ReferralService notes why it earns nothing.
 */
class ReferralCapture
{
    public const INVALID_MESSAGE = 'This referral code is not valid.';

    public function enabled(): bool
    {
        return EarningsSettings::on('referral.enabled');
    }

    /** The referrer for a code, or null if the code is invalid (or blank). */
    public function referrerFor(?string $code, ?string $refereePhone = null): ?User
    {
        $code = Str::upper(trim((string) $code));
        if ($code === '') {
            return null;
        }

        $referrer = User::where('referral_code', $code)->first()
            ?? User::whereRaw('UPPER(referral_code) = ?', [$code])->first();

        if (! $referrer
            || $referrer->role !== 'customer'
            || $referrer->status !== 'active'
            || $referrer->referral_blocked_at !== null) {
            return null;
        }

        if ($refereePhone !== null && PhoneNumber::national((string) $referrer->phone) === PhoneNumber::national($refereePhone)) {
            return null; // self-referral
        }

        return $referrer;
    }

    /**
     * Resolve a submitted code for an account being created. Returns the
     * referrer id to store, null when there is nothing to capture (program
     * off, or no code). Throws InvalidReferralCode for a typed-but-invalid
     * code so each surface can show its own field error.
     *
     * @throws InvalidReferralCode
     */
    public function resolveForSignup(?string $code, ?string $refereePhone): ?int
    {
        if (! $this->enabled() || trim((string) $code) === '') {
            return null;
        }

        $referrer = $this->referrerFor($code, $refereePhone);
        if (! $referrer) {
            throw new InvalidReferralCode(self::INVALID_MESSAGE);
        }

        return $referrer->id;
    }
}
