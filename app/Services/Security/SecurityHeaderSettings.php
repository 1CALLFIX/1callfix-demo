<?php

namespace App\Services\Security;

use App\Models\Setting;

/**
 * Admin-controlled HTTP security response headers (Admin → System → Security headers). Same Setting store and
 * SettingsAuditor as the rest of the admin settings; every value is editable there. A blank value clears the key back
 * to the default listed here, so a typo can never leave the site without a working header set.
 *
 * Defaults are the conservative, can't-break-anything ones: nosniff, referrer policy and same-origin framing ON.
 * The three that can break a live site if wrong — HSTS (browsers remember it), the Content-Security-Policy and the
 * Permissions-Policy — default OFF, and the CSP has a Report-only mode so the owner can watch the browser console
 * before enforcing.
 */
final class SecurityHeaderSettings
{
    public const MASTER = 'security.headers.enabled';
    public const NOSNIFF = 'security.headers.nosniff';
    public const REFERRER = 'security.headers.referrer_policy';
    public const REFERRER_ON = 'security.headers.referrer_policy_enabled';
    public const FRAME = 'security.headers.frame_options';
    public const FRAME_ON = 'security.headers.frame_options_enabled';
    public const HSTS = 'security.headers.hsts';
    public const HSTS_MAX_AGE = 'security.headers.hsts_max_age';
    public const HSTS_SUBDOMAINS = 'security.headers.hsts_subdomains';
    public const HSTS_PRELOAD = 'security.headers.hsts_preload';
    public const PERMISSIONS = 'security.headers.permissions_policy_enabled';
    public const PERMISSIONS_VALUE = 'security.headers.permissions_policy';
    public const CSP_MODE = 'security.headers.csp_mode';
    public const CSP_POLICY = 'security.headers.csp_policy';

    public const REFERRER_OPTIONS = [
        'strict-origin-when-cross-origin', 'same-origin', 'no-referrer', 'origin-when-cross-origin', 'strict-origin', 'no-referrer-when-downgrade',
    ];
    public const FRAME_OPTIONS = ['SAMEORIGIN', 'DENY'];
    public const CSP_MODES = ['off', 'report_only', 'enforce'];

    public const DEFAULT_REFERRER = 'strict-origin-when-cross-origin';
    public const DEFAULT_FRAME = 'SAMEORIGIN';
    public const DEFAULT_HSTS_MAX_AGE = 31536000;
    public const DEFAULT_PERMISSIONS = 'geolocation=(self), camera=(self), microphone=(self), payment=(self "https://checkout.razorpay.com" "https://api.razorpay.com")';

    /** A starting point only — Razorpay, Firebase and Google sign-in allowed; Livewire/Alpine need inline + eval. */
    public const DEFAULT_CSP = "default-src 'self'; "
        ."script-src 'self' 'unsafe-inline' 'unsafe-eval' https://checkout.razorpay.com https://www.gstatic.com https://www.googleapis.com https://apis.google.com; "
        ."style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: blob: https:; "
        ."font-src 'self' data:; "
        ."connect-src 'self' https://*.googleapis.com https://*.firebaseio.com https://fcm.googleapis.com https://api.razorpay.com https://lumberjack.razorpay.com wss:; "
        ."frame-src 'self' https://api.razorpay.com https://checkout.razorpay.com https://*.firebaseapp.com https://accounts.google.com; "
        ."object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self' https://api.razorpay.com";

    /** Header-injection guard: a value is one line of printable ASCII, nothing else. */
    public static function isSafeValue(string $value): bool
    {
        return $value === '' || preg_match('/^[\x20-\x7E]+$/', $value) === 1;
    }

    /** On unless the owner switched the whole feature off. */
    public static function enabled(): bool
    {
        return Setting::get(self::MASTER) !== '0';
    }

    /** Default-on toggle: only an explicit '0' turns it off. */
    private static function on(string $key): bool
    {
        return Setting::get($key) !== '0';
    }

    /** Default-off toggle: only an explicit '1' turns it on. */
    private static function optedIn(string $key): bool
    {
        return Setting::get($key) === '1';
    }

    private static function text(string $key, string $default, ?array $allowed = null): string
    {
        $value = trim((string) Setting::get($key, ''));

        if ($value === '' || ! self::isSafeValue($value) || ($allowed !== null && ! in_array($value, $allowed, true))) {
            return $default;
        }

        return $value;
    }

    public static function nosniff(): bool
    {
        return self::on(self::NOSNIFF);
    }

    public static function referrerPolicy(): ?string
    {
        return self::on(self::REFERRER_ON) ? self::text(self::REFERRER, self::DEFAULT_REFERRER, self::REFERRER_OPTIONS) : null;
    }

    public static function frameOptions(): ?string
    {
        return self::on(self::FRAME_ON) ? self::text(self::FRAME, self::DEFAULT_FRAME, self::FRAME_OPTIONS) : null;
    }

    /** Only meaningful over https; the middleware also checks the request. */
    public static function hsts(): ?string
    {
        if (! self::optedIn(self::HSTS)) {
            return null;
        }

        $age = (int) Setting::get(self::HSTS_MAX_AGE, self::DEFAULT_HSTS_MAX_AGE);
        $age = $age > 0 ? min($age, 63072000) : self::DEFAULT_HSTS_MAX_AGE;
        $value = 'max-age='.$age;

        if (self::optedIn(self::HSTS_SUBDOMAINS)) {
            $value .= '; includeSubDomains';
            if (self::optedIn(self::HSTS_PRELOAD)) {
                $value .= '; preload';
            }
        }

        return $value;
    }

    public static function permissionsPolicy(): ?string
    {
        return self::optedIn(self::PERMISSIONS) ? self::text(self::PERMISSIONS_VALUE, self::DEFAULT_PERMISSIONS) : null;
    }

    public static function cspMode(): string
    {
        $mode = (string) Setting::get(self::CSP_MODE, 'off');

        return in_array($mode, self::CSP_MODES, true) ? $mode : 'off';
    }

    public static function cspPolicy(): string
    {
        return self::text(self::CSP_POLICY, self::DEFAULT_CSP);
    }
}
