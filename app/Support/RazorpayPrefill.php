<?php

namespace App\Support;

use App\Models\User;

/**
 * Owner 2026-11-07: Razorpay's payment window asked the signed-in customer for their name and mobile again. Every
 * checkout now passes what we already know (`prefill` in Razorpay Checkout options), so the customer goes straight to
 * choosing UPI / card. Only filled fields are sent; a guest gets an empty prefill (Razorpay then asks as before).
 */
final class RazorpayPrefill
{
    /** @return array<string, string> */
    public static function for(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_filter([
            'name' => trim((string) $user->name),
            'contact' => PhoneNumber::looksValid((string) $user->phone) ? PhoneNumber::e164((string) $user->phone) : '',
            'email' => filter_var((string) $user->email, FILTER_VALIDATE_EMAIL) ? (string) $user->email : '',
        ], fn ($v) => $v !== '');
    }

    /** @return array<string, string>|object An object when empty, so it reaches the browser as {} rather than []. */
    public static function forCurrentUser(): array|object
    {
        $prefill = self::for(auth()->guard('web')->user());

        return $prefill === [] ? new \stdClass : $prefill;
    }
}
