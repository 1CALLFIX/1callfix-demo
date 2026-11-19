<?php

namespace App\Support;

/**
 * People's posts (owner 2026-11-19): one engine (RentalItem, Admin → People's posts) for two kinds of post.
 *
 * - rent: Rent anything (2026-11-18), /{city}/rent. Gated by Rental on in Modules and "Rent anything" on in Rental
 *   Settings, zone by zone.
 * - sell: Second-hand (2026-11-19), /{city}/second-hand. Its own switch `secondhand.enabled`, zone by zone, not tied to
 *   the Rental module (selling used things is not rental). Named "Second-hand", never "Sale" / "Sell" / "Seller" /
 *   "Resale": on this site those mean shop discounts, shop vendors and resale flats.
 *
 * Everything that differs between the two lives here: which settings keys, the on/off check, the words and the links.
 */
final class PostTypes
{
    public const RENT = 'rent';
    public const SELL = 'sell';

    public const ALL = [self::RENT, self::SELL];

    /** Settings with their own value for Second-hand; every other `items.*` setting is shared by both. */
    private const OWN_SETTINGS = ['categories', 'conditions', 'banned', 'max_live_per_person', 'min_price', 'max_price'];

    public static function valid(?string $deal): string
    {
        return $deal === self::SELL ? self::SELL : self::RENT;
    }

    /** The settings key for this post type: `secondhand.categories` for a sale, `items.categories` otherwise. */
    public static function key(string $deal, string $name): string
    {
        return $deal === self::SELL && in_array($name, self::OWN_SETTINGS, true) ? 'secondhand.'.$name : 'items.'.$name;
    }

    /** Customers can see (and people can post) this type of post in this scope. */
    public static function onIn(string $deal, array $scope): bool
    {
        return $deal === self::SELL
            ? RentalItemSettings::on('secondhand.enabled', $scope)
            : RentalKinds::active(RentalKinds::ITEMS, $scope);
    }

    /** Words: a Second-hand page uses `secondhand.<key>` where it has its own words, else the shared `items.<key>`. */
    public static function text(string $deal, string $key, array $replace = []): string
    {
        if ($deal === self::SELL && array_key_exists('secondhand.'.$key, SiteText::TEXTS)) {
            return SiteText::get('secondhand.'.$key, $replace);
        }

        return SiteText::get('items.'.$key, $replace);
    }

    /**
     * Route names: home / show (public, under the city), mine / create / edit (Account).
     */
    public static function route(string $deal, string $page): string
    {
        $public = $deal === self::SELL ? 'customer.secondhand' : 'customer.rent';
        $account = $deal === self::SELL ? 'customer.secondhand-items' : 'customer.rent-items';

        return match ($page) {
            'home' => $public.'.home',
            'show' => $public.'.show',
            'mine' => $account,
            'create' => $account.'.create',
            'edit' => $account.'.edit',
        };
    }

    /** The one public link of a post: /{city}/rent/{slug} or /{city}/second-hand/{slug}. */
    public static function url(\App\Models\RentalItem $item, string $citySlug): string
    {
        return route(self::route($item->deal ?? self::RENT, 'show'), [$citySlug, $item->slug]);
    }

    /** Short label for the team's queue. */
    public static function label(string $deal): string
    {
        return $deal === self::SELL ? 'Second-hand' : 'For rent';
    }
}
