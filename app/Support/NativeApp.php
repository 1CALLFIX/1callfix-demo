<?php

namespace App\Support;

/**
 * Which 1CallFix Android app is showing this page (mobile/, Capacitor shells over the site). Each flavor adds
 * "1CallFixApp/customer|rider|partner" to its user agent (mobile/android/app/src/<flavor>/assets/capacitor.config.json).
 * A normal browser has neither, so app-only things (Rate & review) never show on the website.
 */
final class NativeApp
{
    /** flavor => Play Store package (the live Play apps' names). */
    public const PACKAGES = [
        'customer' => 'com.call.customer',
        'rider' => 'com.onecall.deliveryboy',
        'partner' => 'com.onecall.vendor',
    ];

    public static function inApp(): bool
    {
        return str_contains((string) request()->userAgent(), '1CallFixApp');
    }

    /** customer | rider | partner, or null outside the apps / on an app build without a flavor tag. */
    public static function flavor(): ?string
    {
        if (preg_match('#1CallFixApp/(customer|rider|partner)\b#', (string) request()->userAgent(), $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * The Play Store page to rate the app this page is open in, or null in a browser. $default is the app this
     * screen belongs to, used when the app sends no flavor tag. Opens in the Play Store app (not in the shell).
     */
    public static function rateUrl(string $default): ?string
    {
        if (! self::inApp()) {
            return null;
        }
        $package = self::PACKAGES[self::flavor() ?? $default] ?? null;

        return $package ? 'https://play.google.com/store/apps/details?id='.$package : null;
    }
}
