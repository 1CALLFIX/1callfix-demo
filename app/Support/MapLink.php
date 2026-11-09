<?php

namespace App\Support;

/**
 * Map links that open the phone's maps app, never a small map inside a browser tab (owner 2026-11-09: "Directions
 * opens maps in Chrome on the phone, the map is very small; wherever a location is shown, open Google Maps or any
 * maps app").
 *
 * - Android (our apps and any Android browser): an intent:// link aimed at the Google Maps app. The app shells hand
 *   intent:// links to Android (mobile/android/.../MainActivity::openOutside), Chrome does the same. A phone without
 *   Google Maps falls back to the normal web link.
 * - iPhone / iPad: Apple Maps (always on the phone).
 * - Computers: the normal Google Maps page in a new tab.
 */
final class MapLink
{
    public const GOOGLE_MAPS_PACKAGE = 'com.google.android.apps.maps';

    /** Turn-by-turn to a point. $navigate starts the drive straight away (riders, partners). */
    public static function directions(mixed $lat, mixed $lng, bool $navigate = true): ?string
    {
        if (! self::valid($lat, $lng)) {
            return null;
        }
        $point = self::point($lat, $lng);
        if (self::platform() === 'ios') {
            return 'https://maps.apple.com/?daddr='.$point.'&dirflg=d';
        }

        return self::open('https://www.google.com/maps/dir/?api=1&destination='.$point.($navigate && self::platform() === 'android' ? '&dir_action=navigate' : ''));
    }

    /** A pin on the map (where a rider is, where a photo was taken). */
    public static function place(mixed $lat, mixed $lng): ?string
    {
        if (! self::valid($lat, $lng)) {
            return null;
        }
        $point = self::point($lat, $lng);
        if (self::platform() === 'ios') {
            return 'https://maps.apple.com/?ll='.$point.'&q='.$point;
        }

        return self::open('https://www.google.com/maps/search/?api=1&query='.$point);
    }

    /** Any Google Maps web link (e.g. the office map link Super Admin pastes): opened in the app on Android. */
    public static function open(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        if (self::platform() !== 'android' || ! self::isGoogleMaps($url)) {
            return $url;
        }

        return 'intent://'.preg_replace('#^https?://#i', '', $url)
            .'#Intent;scheme=https;package='.self::GOOGLE_MAPS_PACKAGE
            .';S.browser_fallback_url='.rawurlencode($url).';end';
    }

    /** Web links open in a new tab on computers; app links (intent://) must not leave an empty tab behind. */
    public static function newTab(?string $href): bool
    {
        return $href !== null && str_starts_with($href, 'http');
    }

    /** android | ios | other, from the browser / app user agent. */
    public static function platform(): string
    {
        $ua = (string) request()->userAgent();
        if (str_contains($ua, 'Android') || str_contains($ua, '1CallFixApp')) {
            return 'android';
        }
        if (preg_match('/iPhone|iPad|iPod/', $ua)) {
            return 'ios';
        }

        return 'other';
    }

    private static function isGoogleMaps(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        return in_array($host, ['maps.google.com', 'maps.app.goo.gl'], true)
            || (preg_match('/(^|\.)google\.[a-z.]+$/', $host) && str_starts_with($path, '/maps'));
    }

    private static function valid(mixed $lat, mixed $lng): bool
    {
        return is_numeric($lat) && is_numeric($lng) && ! ((float) $lat == 0.0 && (float) $lng == 0.0);
    }

    private static function point(mixed $lat, mixed $lng): string
    {
        return rtrim(rtrim(sprintf('%.6F', (float) $lat), '0'), '.').','.rtrim(rtrim(sprintf('%.6F', (float) $lng), '0'), '.');
    }
}
