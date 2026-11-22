<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Admin → Settings → App Upgrade (owner 2026-11-22). Per Android app (customer / partner / rider): the newest version
 * code on the Play Store and what to do with phones on an older build — nothing, ask (a "Later" button), or must
 * update (a full screen with only the Update button). public/js/app-update.js does the check inside the app: it reads
 * the phone's own build with the Capacitor App plugin (in every shell since the first one) and does nothing when it
 * cannot, so a browser or an unreadable build is never blocked. Global only: the same app runs in every city.
 */
final class AppUpgrade
{
    public const MODES = ['off', 'ask', 'must'];

    /** flavor => [label, colour] */
    public const APPS = [
        'customer' => ['Customer app', 'blue'],
        'partner' => ['Partner app (shops, restaurants, pros, hotels, halls, events)', 'emerald'],
        'rider' => ['Rider app (parcel & delivery)', 'orange'],
    ];

    public static function latestKey(string $app): string
    {
        return "app_upgrade.{$app}.latest_build";
    }

    public static function modeKey(string $app): string
    {
        return "app_upgrade.{$app}.mode";
    }

    /** @return array{latest: int|null, mode: string} */
    public static function forApp(string $app): array
    {
        $latest = (int) Setting::get(self::latestKey($app), 0);
        $mode = (string) Setting::get(self::modeKey($app), 'off');

        return [
            'latest' => $latest > 0 ? $latest : null,
            'mode' => in_array($mode, self::MODES, true) ? $mode : 'off',
        ];
    }

    /**
     * What the in-app script needs: only the apps that are switched on and have a version code, each with its Play
     * Store page. Empty = nothing to check, and the script tag is not printed at all.
     *
     * @return array<string, array{latest: int, mode: string, store: string}>
     */
    public static function clientConfig(): array
    {
        $out = [];
        foreach (array_keys(self::APPS) as $app) {
            $c = self::forApp($app);
            if ($c['mode'] !== 'off' && $c['latest'] !== null) {
                $out[$app] = [
                    'latest' => $c['latest'],
                    'mode' => $c['mode'],
                    'store' => 'https://play.google.com/store/apps/details?id='.NativeApp::PACKAGES[$app],
                ];
            }
        }

        return $out;
    }
}
