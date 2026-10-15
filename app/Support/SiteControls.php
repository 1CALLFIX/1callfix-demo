<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Admin → Homepage & sign-up controls (Super Admin only). One place for the switches that shape the homepage opener,
 * how catalogue images are drawn, the "services you offer" step of provider sign-up, and the sign-up attempt limits.
 *
 * Values live in the one settings store (Setting::get / SettingsAuditor::put). An unset key reads as the default
 * below, so nothing changes on a server until the owner saves the screen — except the defaults chosen here, which
 * are the owner's 2026-10-15 brief: category icons are the homepage opener, photo tiles are off, images are never
 * cropped or zoomed.
 */
final class SiteControls
{
    // Homepage opener
    public const HOME_HEADLINE = 'home.layout.headline';
    public const HOME_SHOW_HEADLINE = 'home.layout.show_headline';
    public const HOME_SHOW_PHOTOS = 'home.layout.show_photo_tiles';
    public const HOME_CATEGORY_LIMIT = 'home.layout.category_limit';
    public const HOME_COLUMNS_MOBILE = 'home.layout.columns_mobile';
    public const HOME_COLUMNS_DESKTOP = 'home.layout.columns_desktop';
    public const HOME_CATEGORIES_FIRST = 'home.layout.categories_first';
    public const HOME_ICON_SIZE = 'home.layout.icon_size';

    // Images
    public const IMAGE_FIT = 'images.fit';
    public const IMAGE_BORDER = 'images.border';
    public const IMAGE_GLOSS = 'images.gloss';

    // Provider sign-up: services step
    public const SIGNUP_SERVICES = 'provider_signup.services_step';
    public const SIGNUP_SERVICES_MAX = 'provider_signup.services_max';

    // Sign-up attempt limits
    public const LIMIT_PARTNER_FORM = 'signup_limits.partner_form_per_mobile';
    public const LIMIT_PARTNER_WINDOW = 'signup_limits.partner_form_window_minutes';
    public const LIMIT_OTP = 'signup_limits.otp_per_mobile';
    public const LIMIT_SUBMIT = 'signup_limits.submit_per_mobile';
    public const LIMIT_WINDOW = 'signup_limits.window_minutes';

    public const SERVICES_MODES = ['required', 'optional', 'hidden'];
    public const FITS = ['contain', 'cover'];
    public const ICON_SIZES = ['small', 'medium', 'large'];

    public const DEFAULT_HEADLINE = 'Home services at your doorstep';

    public static function headline(): string
    {
        $v = trim((string) self::raw(self::HOME_HEADLINE));

        return $v !== '' ? $v : self::DEFAULT_HEADLINE;
    }

    public static function showHeadline(): bool
    {
        return self::flag(self::HOME_SHOW_HEADLINE, true);
    }

    /** The photo tiles beside the categories. Off by default: the owner found them cluttered (2026-10-15). */
    public static function showPhotoTiles(): bool
    {
        return self::flag(self::HOME_SHOW_PHOTOS, false);
    }

    /** Category icons above the top banner (default), so the first screen says what we do. */
    public static function categoriesFirst(): bool
    {
        return self::flag(self::HOME_CATEGORIES_FIRST, true);
    }

    public static function categoryLimit(): int
    {
        return self::int(self::HOME_CATEGORY_LIMIT, 16, 4, 40);
    }

    public static function columnsMobile(): int
    {
        return self::int(self::HOME_COLUMNS_MOBILE, 4, 3, 6);
    }

    public static function columnsDesktop(): int
    {
        return self::int(self::HOME_COLUMNS_DESKTOP, 8, 4, 12);
    }

    /** Homepage category icon size: small | medium (default) | large. */
    public static function iconSize(): string
    {
        $v = (string) self::raw(self::HOME_ICON_SIZE);

        return in_array($v, self::ICON_SIZES, true) ? $v : 'medium';
    }

    /** Tailwind size classes for the homepage icon box (full literals so the build keeps them). */
    public static function iconBoxClasses(): string
    {
        return match (self::iconSize()) {
            'small' => 'h-12 w-12 sm:h-14 sm:w-14',
            'large' => 'h-20 w-20 sm:h-24 sm:w-24',
            default => 'h-16 w-16 sm:h-20 sm:w-20',
        };
    }

    /** 'contain' = the whole picture, never cropped or zoomed (default); 'cover' = fill the box, edges may be cut. */
    public static function imageFit(): string
    {
        $v = (string) self::raw(self::IMAGE_FIT);

        return in_array($v, self::FITS, true) ? $v : 'contain';
    }

    public static function imageBorder(): bool
    {
        return self::flag(self::IMAGE_BORDER, true);
    }

    public static function imageGloss(): bool
    {
        return self::flag(self::IMAGE_GLOSS, true);
    }

    /** Tailwind classes for an <img> inside a fixed box, per the image settings. */
    public static function imageClasses(): string
    {
        return self::imageFit() === 'cover' ? 'object-cover' : 'object-contain';
    }

    /** Classes for the frame around a catalogue image (border + gloss), per the image settings. */
    public static function frameClasses(): string
    {
        return trim((self::imageBorder() ? 'cf-img-border ' : '').(self::imageGloss() ? 'cf-img-gloss' : ''));
    }

    /** required | optional | hidden — the "services you offer" step of provider sign-up. */
    public static function servicesStep(): string
    {
        $v = (string) self::raw(self::SIGNUP_SERVICES);

        return in_array($v, self::SERVICES_MODES, true) ? $v : 'required';
    }

    /** Most categories an applicant may tick; 0 = no limit. */
    public static function servicesMax(): int
    {
        return self::int(self::SIGNUP_SERVICES_MAX, 0, 0, 50);
    }

    public static function partnerFormPerMobile(): int
    {
        return self::int(self::LIMIT_PARTNER_FORM, 10, 1, 100);
    }

    public static function partnerFormWindowSeconds(): int
    {
        return 60 * self::int(self::LIMIT_PARTNER_WINDOW, 10, 1, 1440);
    }

    public static function otpPerMobile(): int
    {
        return self::int(self::LIMIT_OTP, 5, 1, 50);
    }

    public static function submitPerMobile(): int
    {
        return self::int(self::LIMIT_SUBMIT, 5, 1, 100);
    }

    public static function windowSeconds(): int
    {
        return 60 * self::int(self::LIMIT_WINDOW, 1, 1, 1440);
    }

    /**
     * Settings are read once per request: catalogue cards call these per card, and the cache store is the database.
     * Request attributes (not a static) so a saved change is seen by the very next request and tests stay isolated.
     */
    private static function raw(string $key): ?string
    {
        $bag = request()->attributes;
        $memo = 'site_controls:'.$key;
        if (! $bag->has($memo)) {
            $v = Setting::get($key, null);
            $bag->set($memo, $v === null ? null : (string) $v);
        }

        return $bag->get($memo);
    }

    private static function flag(string $key, bool $default): bool
    {
        $v = self::raw($key);

        return $v === null || $v === '' ? $default : in_array(strtolower((string) $v), ['1', 'true', 'on', 'yes'], true);
    }

    private static function int(string $key, int $default, int $min, int $max): int
    {
        $v = self::raw($key);
        if ($v === null || $v === '' || ! is_numeric($v)) {
            return $default;
        }

        return max($min, min($max, (int) $v));
    }
}
