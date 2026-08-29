<?php

namespace App\Support;

/**
 * One place to reconcile the several shapes a mobile number arrives in:
 *
 *  - existing `users.phone` rows are bare national digits ("9876543210"),
 *    the only shape this codebase has ever stored (see
 *    CustomerAccountResolver and the pre-rebuild Login::normalisedPhone);
 *  - Firebase phone-auth ID tokens carry E.164 ("+919876543210");
 *  - customers type numbers with spaces, dashes, a leading 0, or a +CC.
 *
 * `national()` collapses all of them to the bare national form so a
 * Firebase-verified number can be matched against, and stored the same way
 * as, the rows that already exist. India (country code 91) is assumed,
 * consistent with the rest of the app (MSG91 route, "Indian numbers" note
 * in config/services.php); the country code is overridable via
 * config('services.sms.country_code').
 */
final class PhoneNumber
{
    public static function countryCode(): string
    {
        return (string) config('services.sms.country_code', '91');
    }

    /**
     * Bare national-format digits. Idempotent: national(national($x)) === national($x).
     */
    public static function national(string $input): string
    {
        $digits = preg_replace('/\D+/', '', $input) ?? '';

        if ($digits === '') {
            return '';
        }

        $cc = self::countryCode();

        // International access prefix: "0091XXXXXXXXXX" → "91XXXXXXXXXX".
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // "+91XXXXXXXXXX" / "91XXXXXXXXXX" (12 digits) → strip the 91.
        if (str_starts_with($digits, $cc) && strlen($digits) === strlen($cc) + 10) {
            $digits = substr($digits, strlen($cc));
        }

        // Leading trunk "0" ("09876543210") → strip it.
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return $digits;
    }

    /** E.164 ("+91XXXXXXXXXX") for anywhere an external system wants it. */
    public static function e164(string $input): string
    {
        $national = self::national($input);

        return $national === '' ? '' : '+'.self::countryCode().$national;
    }

    public static function looksValid(string $input): bool
    {
        return strlen(self::national($input)) === 10;
    }
}
