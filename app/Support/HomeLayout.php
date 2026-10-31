<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The order of the homepage sections and which ones show (owner 2026-10-31: "every small thing must be controlled by
 * super Admin"), set from Admin → Growth → Homepage sections. A section with no real data still renders nothing,
 * whatever is set here.
 *
 * Until the Super Admin saves an order, the order comes from the older position settings (doors top/under the
 * icons, category icons first, parcel card position), so nothing moves on deploy. Once saved, the saved order wins;
 * a section added in a later release slots in at its default place.
 */
final class HomeLayout
{
    public const ORDER = 'home.layout.order';

    /** section => label in Admin, in the default order. */
    public const SECTIONS = [
        'doors' => 'Explore doors (Services / Parcel / Shop)',
        'categories' => 'Category icons',
        'hero_banner' => 'Top banner',
        'parcel_card' => 'Send a parcel card',
        'module_cards' => 'Business cards (each business with its icons and offer line)',
        'new' => 'New & noteworthy',
        'most_booked' => 'Most booked services',
        'mid_banner_1' => 'Middle banner 1',
        'collections' => 'Category rows of services',
        'mid_banner_2' => 'Middle banner 2',
        'offers' => 'Offers on now',
        'mid_banner_3' => 'Middle banner 3',
        'membership' => 'Membership',
        'reviews' => 'Google reviews',
        'trust' => 'Why choose us',
        'faq' => 'Questions (FAQ)',
    ];

    public static function hasSavedOrder(): bool
    {
        return self::saved() !== [];
    }

    /** Every section key, in order (shown or not). @return list<string> */
    public static function order(): array
    {
        $saved = self::saved();
        if ($saved === []) {
            return self::legacyOrder();
        }
        // A section added later than the save goes in after the section that comes before it by default.
        $defaults = array_keys(self::SECTIONS);
        foreach ($defaults as $i => $key) {
            if (! in_array($key, $saved, true)) {
                $before = $i > 0 ? array_search($defaults[$i - 1], $saved, true) : -1;
                array_splice($saved, $before === false ? count($saved) : $before + 1, 0, [$key]);
            }
        }

        return $saved;
    }

    /** The sections to draw, in order. @return list<string> */
    public static function visible(): array
    {
        return array_values(array_filter(self::order(), fn ($key) => self::shown($key)));
    }

    public static function showKey(string $section): string
    {
        return "home.section.{$section}.show";
    }

    public static function shown(string $section): bool
    {
        $v = self::raw(self::showKey($section));

        return $v === null || $v === '' ? true : in_array(strtolower($v), ['1', 'true', 'on', 'yes'], true);
    }

    /** The order the older position settings give (what the homepage looked like before this screen). */
    private static function legacyOrder(): array
    {
        $doors = HomeDoors::position();
        $parcel = SiteControls::parcelPosition();
        $first = SiteControls::categoriesFirst();

        $order = $first ? [] : ['hero_banner'];
        if ($doors === 'top') {
            $order[] = 'doors';
        }
        if ($parcel === 'top') {
            $order[] = 'parcel_card';
        }
        $order[] = 'categories';
        if ($doors === 'after_categories') {
            $order[] = 'doors';
        }
        if ($parcel === 'after_categories') {
            $order[] = 'parcel_card';
        }
        if ($first) {
            $order[] = 'hero_banner';
        }
        if ($parcel === 'after_banner') {
            $order[] = 'parcel_card';
        }

        return array_merge($order, array_values(array_diff(array_keys(self::SECTIONS), $order)));
    }

    /** @return list<string> */
    private static function saved(): array
    {
        $keys = array_filter(array_map('trim', explode(',', (string) self::raw(self::ORDER))));

        return array_values(array_unique(array_filter($keys, fn ($k) => array_key_exists($k, self::SECTIONS))));
    }

    public static function forget(): void
    {
        foreach (array_keys(request()->attributes->all()) as $k) {
            if (str_starts_with((string) $k, 'home_layout:')) {
                request()->attributes->remove($k);
            }
        }
    }

    private static function raw(string $key): ?string
    {
        $bag = request()->attributes;
        $memo = 'home_layout:'.$key;
        if (! $bag->has($memo)) {
            $v = Setting::get($key, null);
            $bag->set($memo, $v === null ? null : (string) $v);
        }

        return $bag->get($memo);
    }
}
