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

    // Homepage parcel card + the Parcel icon in the category grid (owner 2026-10-26). Shown only where Parcel is on.
    public const PARCEL_POSITION = 'home.parcel.position';
    public const PARCEL_GRID_TILE = 'home.parcel.grid_tile';
    public const PARCEL_NEW_BADGE = 'home.parcel.new_badge';
    public const PARCEL_TITLE = 'home.parcel.title';
    public const PARCEL_SUBTITLE = 'home.parcel.subtitle';
    public const PARCEL_SHOW_PRICE = 'home.parcel.show_price';
    public const PARCEL_COLOUR = 'home.parcel.colour';
    public const PARCEL_IMAGE = 'home.parcel.image';
    public const PARCEL_ICON = 'home.parcel.icon';
    public const PARCEL_SHOW_CHIPS = 'home.parcel.show_chips';
    public const PARCEL_CHIPS = 'home.parcel.chips';
    public const PARCEL_SEND_LABEL = 'home.parcel.send_label';
    public const PARCEL_TRACK_LABEL = 'home.parcel.track_label';
    public const PARCEL_BADGE_TEXT = 'home.parcel.badge_text';
    public const PARCEL_PAGE_HEADING = 'parcel.page.heading';
    public const PARCEL_PAGE_INTRO = 'parcel.page.intro';

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

    // Admin → Partners, Needs review (owner 2026-10-27): a wait turns amber, then red ("Overdue"), after these hours.
    public const REVIEW_AMBER_HOURS = 'partners.review_amber_hours';
    public const REVIEW_RED_HOURS = 'partners.review_red_hours';

    public const SERVICES_MODES = ['required', 'optional', 'hidden'];
    public const FITS = ['contain', 'cover'];
    public const ICON_SIZES = ['small', 'medium', 'large'];

    public const DEFAULT_HEADLINE = 'Home services at your doorstep';

    /** top = above the category icons; after_categories = between the icons and the top banner; after_banner = today's spot. */
    public const PARCEL_POSITIONS = ['top', 'after_categories', 'after_banner'];
    public const PARCEL_COLOURS = ['blue', 'amber', 'emerald', 'slate'];
    public const DEFAULT_PARCEL_TITLE = 'Parcel';
    public const DEFAULT_PARCEL_SUBTITLE = 'Doorstep pickup & delivery';
    public const DEFAULT_PARCEL_CHIPS = '🔑 Keys, 📄 Documents, 🍱 Lunch box';
    public const DEFAULT_PARCEL_SEND_LABEL = 'Send now →';
    public const DEFAULT_PARCEL_TRACK_LABEL = 'Track my parcel';
    public const DEFAULT_PARCEL_BADGE_TEXT = 'NEW';
    public const DEFAULT_PARCEL_PAGE_HEADING = 'Send a parcel';
    public const DEFAULT_PARCEL_PAGE_INTRO = 'A rider picks it up and delivers it the same trip. Pay online, from your wallet, or cash at pickup or drop.';
    /** Built-in pictures (our own drawings), used until the Super Admin uploads one. */
    public const DEFAULT_PARCEL_IMAGE = 'images/parcel/parcel-rider.svg';
    public const DEFAULT_PARCEL_ICON = 'images/parcel/parcel-icon.svg';

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

    /** Where the parcel card sits on the homepage. Default after_banner, where it has been since parcel launched. */
    public static function parcelPosition(): string
    {
        $v = (string) self::raw(self::PARCEL_POSITION);

        return in_array($v, self::PARCEL_POSITIONS, true) ? $v : 'after_banner';
    }

    /** A Parcel icon as the first tile of the category grid. */
    public static function parcelGridTile(): bool
    {
        return self::flag(self::PARCEL_GRID_TILE, true);
    }

    public static function parcelNewBadge(): bool
    {
        return self::flag(self::PARCEL_NEW_BADGE, true);
    }

    public static function parcelTitle(): string
    {
        $v = trim((string) self::raw(self::PARCEL_TITLE));

        return $v !== '' ? $v : self::DEFAULT_PARCEL_TITLE;
    }

    public static function parcelSubtitle(): string
    {
        $v = trim((string) self::raw(self::PARCEL_SUBTITLE));

        return $v !== '' ? $v : self::DEFAULT_PARCEL_SUBTITLE;
    }

    public static function parcelShowChips(): bool
    {
        return self::flag(self::PARCEL_SHOW_CHIPS, true);
    }

    /** The card chips as typed in admin, comma-separated. */
    public static function parcelChipsText(): string
    {
        return self::text(self::PARCEL_CHIPS, self::DEFAULT_PARCEL_CHIPS);
    }

    /** @return list<string> the chips to draw; empty when the chips switch is off. */
    public static function parcelChips(): array
    {
        return self::parcelShowChips()
            ? array_values(array_filter(array_map('trim', explode(',', self::parcelChipsText())), fn ($c) => $c !== ''))
            : [];
    }

    public static function parcelSendLabel(): string
    {
        return self::text(self::PARCEL_SEND_LABEL, self::DEFAULT_PARCEL_SEND_LABEL);
    }

    public static function parcelTrackLabel(): string
    {
        return self::text(self::PARCEL_TRACK_LABEL, self::DEFAULT_PARCEL_TRACK_LABEL);
    }

    /** The label text on the Parcel icon (drawn when the label switch is on). */
    public static function parcelBadgeText(): string
    {
        return self::text(self::PARCEL_BADGE_TEXT, self::DEFAULT_PARCEL_BADGE_TEXT);
    }

    public static function parcelPageHeading(): string
    {
        return self::text(self::PARCEL_PAGE_HEADING, self::DEFAULT_PARCEL_PAGE_HEADING);
    }

    public static function parcelPageIntro(): string
    {
        return self::text(self::PARCEL_PAGE_INTRO, self::DEFAULT_PARCEL_PAGE_INTRO);
    }

    /** "from ₹…" on the card, read from the area's Parcel Settings base fare (hidden when that is unset). */
    public static function parcelShowPrice(): bool
    {
        return self::flag(self::PARCEL_SHOW_PRICE, true);
    }

    public static function parcelColour(): string
    {
        $v = (string) self::raw(self::PARCEL_COLOUR);

        return in_array($v, self::PARCEL_COLOURS, true) ? $v : 'blue';
    }

    /** Card background, title and button classes per colour (full literals so the build keeps them). */
    public static function parcelCardClasses(): array
    {
        return match (self::parcelColour()) {
            'amber' => ['card' => 'from-amber-300 to-amber-500 text-slate-900 ring-blue-600/40', 'sub' => 'text-slate-800', 'chip' => 'bg-white/40', 'primary' => 'bg-blue-700 text-white hover:bg-blue-800', 'secondary' => 'bg-white/40 ring-slate-900/20 hover:bg-white/60', 'price' => 'bg-blue-700 text-white'],
            'emerald' => ['card' => 'from-emerald-500 to-emerald-700 text-white ring-amber-300/70', 'sub' => 'text-emerald-50', 'chip' => 'bg-white/15', 'primary' => 'bg-amber-300 text-slate-900 hover:bg-amber-200', 'secondary' => 'bg-white/15 ring-white/40 hover:bg-white/25', 'price' => 'bg-amber-300 text-slate-900'],
            'slate' => ['card' => 'from-slate-800 to-slate-950 text-white ring-amber-300/70', 'sub' => 'text-slate-300', 'chip' => 'bg-white/10', 'primary' => 'bg-amber-300 text-slate-900 hover:bg-amber-200', 'secondary' => 'bg-white/10 ring-white/30 hover:bg-white/20', 'price' => 'bg-amber-300 text-slate-900'],
            default => ['card' => 'from-blue-600 to-blue-800 text-white ring-amber-300/70', 'sub' => 'text-blue-100', 'chip' => 'bg-white/15', 'primary' => 'bg-amber-300 text-slate-900 hover:bg-amber-200', 'secondary' => 'bg-white/15 ring-white/40 hover:bg-white/25', 'price' => 'bg-amber-300 text-slate-900'],
        };
    }

    /** The card / parcel page picture: the uploaded one, else the built-in drawing. */
    public static function parcelImageUrl(): string
    {
        return self::uploadedOr(self::PARCEL_IMAGE, self::DEFAULT_PARCEL_IMAGE);
    }

    /** The Parcel icon in the category grid: the uploaded one, else the built-in icon. */
    public static function parcelIconUrl(): string
    {
        return self::uploadedOr(self::PARCEL_ICON, self::DEFAULT_PARCEL_ICON);
    }

    /** Whether a picture has been uploaded for $key (otherwise the built-in one shows). */
    public static function hasUpload(string $key): bool
    {
        return trim((string) self::raw($key)) !== '';
    }

    private static function uploadedOr(string $key, string $default): string
    {
        $path = trim((string) self::raw($key));

        return $path !== '' ? \Illuminate\Support\Facades\Storage::disk('public')->url($path) : asset($default);
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

    /** Hours a partner's documents may wait before the wait shows amber. */
    public static function reviewAmberHours(): int
    {
        return self::int(self::REVIEW_AMBER_HOURS, 24, 1, 720);
    }

    /** Hours before a waiting partner is Overdue (red row, badge, menu dot). Never below the amber hours. */
    public static function reviewRedHours(): int
    {
        return max(self::reviewAmberHours(), self::int(self::REVIEW_RED_HOURS, 48, 1, 720));
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

    /** A text setting; blank or unset reads as the default. */
    private static function text(string $key, string $default): string
    {
        $v = trim((string) self::raw($key));

        return $v !== '' ? $v : $default;
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
