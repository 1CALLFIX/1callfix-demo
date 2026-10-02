<?php

namespace App\Services\Cancellation;

use App\Models\Booking;

/**
 * REF 1CF-CANCEL-POLICY-001 — stateless, signed quote. Binds the confirmation to the exact booking, status, hold
 * reason and charge the customer was shown, with a short expiry. The server still re-evaluates everything under
 * the row lock; the token only proves the customer saw (and agreed to) THIS amount.
 */
final class CancellationQuoteToken
{
    public const TTL_MINUTES = 10;

    public static function issue(Booking $booking, float $charge): string
    {
        $expires = now()->addMinutes(self::TTL_MINUTES)->timestamp;

        return $expires.'.'.self::sign($booking, $charge, $expires);
    }

    public static function valid(Booking $booking, float $charge, ?string $token): bool
    {
        if (! $token || substr_count($token, '.') !== 1) {
            return false;
        }

        [$expires, $sig] = explode('.', $token);

        return ctype_digit($expires)
            && (int) $expires >= now()->timestamp
            && hash_equals(self::sign($booking, $charge, (int) $expires), $sig);
    }

    private static function sign(Booking $booking, float $charge, int $expires): string
    {
        $payload = implode('|', [$booking->id, $booking->customer_id, $booking->status, (string) $booking->hold_reason, number_format($charge, 2, '.', ''), $expires]);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }
}
