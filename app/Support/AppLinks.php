<?php

namespace App\Support;

use App\Support\PartnerPage\FooterLinks;

/**
 * Where "Get the app" sends people (owner 2026-11-08). Android: the link the Super Admin typed under Partner page →
 * App links, else the customer app's own Play Store page (always known, so the buttons never disappear). iPhone: only
 * a typed link; there is no iOS app, so iPhone visitors get the "Add to Home Screen" steps instead.
 */
final class AppLinks
{
    /** The fixed, printable link (QR code, posters, WhatsApp): /app?go=1 sends Android phones straight to the store. */
    public const SHORT_PATH = '/app';

    public static function android(): string
    {
        return FooterLinks::appUrl('store.android_url')
            ?? 'https://play.google.com/store/apps/details?id='.NativeApp::PACKAGES['customer'];
    }

    public static function ios(): ?string
    {
        return FooterLinks::appUrl('store.ios_url');
    }

    public static function isAndroid(?string $userAgent): bool
    {
        return $userAgent !== null && stripos($userAgent, 'Android') !== false;
    }
}
