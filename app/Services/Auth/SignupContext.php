<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;

/**
 * REF 1CF-PROMPT-20260925-EARN4 — what a signup surface knows about the
 * request that is creating an account, handed to CustomerAccountResolver so
 * it lands on the row AT creation (R1: a referral code is only ever
 * accepted when the account is created, never attached later).
 *
 * deviceId is the app's X-Device-Id header, trimmed and length-capped; the
 * web sends none (null). referrerId is already resolved and validated by
 * ReferralCapture — the resolver never looks a code up itself.
 */
final class SignupContext
{
    public const DEVICE_HEADER = 'X-Device-Id';

    public function __construct(
        public readonly ?string $ip = null,
        public readonly ?string $deviceId = null,
        public readonly ?int $referrerId = null,
    ) {
    }

    public static function fromRequest(Request $request, ?int $referrerId = null): self
    {
        return new self($request->ip(), self::deviceIdFrom($request), $referrerId);
    }

    public static function deviceIdFrom(Request $request): ?string
    {
        $id = trim((string) $request->header(self::DEVICE_HEADER));

        return $id === '' ? null : mb_substr($id, 0, 191);
    }
}
