<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The homepage business cards (owner samples 2026-10-31): one card per business — a heading, "View all", a row of
 * icons, one offer line and "More". Cards: each service category (its subcategories as icons), Parcel and Shop.
 * A card shows only where its business is live and has something real to show. The Super Admin sets icons per card,
 * how many category cards, the "New" badge window, and per card: on/off, order and the offer line in English and
 * Telugu (Admin → Growth → Homepage sections). Shared words are in SiteText.
 */
final class HomeCards
{
    public const ICONS = 'home.cards.icons';
    public const CATEGORY_LIMIT = 'home.cards.category_limit';
    public const NEW_DAYS = 'home.cards.new_days';

    public const ICON_CHOICES = [4, 8];

    public static function icons(): int
    {
        $v = (int) self::raw(self::ICONS);

        return in_array($v, self::ICON_CHOICES, true) ? $v : 4;
    }

    public static function categoryLimit(): int
    {
        $v = self::raw(self::CATEGORY_LIMIT);

        return is_numeric($v) ? max(0, min(20, (int) $v)) : 6;
    }

    /** A "New" badge on an icon added in the last N days. 0 = no badges. */
    public static function newDays(): int
    {
        $v = self::raw(self::NEW_DAYS);

        return is_numeric($v) ? max(0, min(365, (int) $v)) : 30;
    }

    /** Card keys: parcel, shop, category_{category id}. Category cards default 10, 20, 30…; parcel and shop sit between. */
    public static function defaultOrder(string $card, int $index = 0): int
    {
        return match ($card) {
            'parcel' => 25,
            'shop' => 35,
            default => ($index + 1) * 10,
        };
    }

    public static function showKey(string $card): string
    {
        return "home.card.{$card}.show";
    }

    public static function orderKey(string $card): string
    {
        return "home.card.{$card}.order";
    }

    public static function lineKey(string $card, string $locale): string
    {
        return "home.card.{$card}.line.{$locale}";
    }

    public static function cardShown(string $card): bool
    {
        $v = self::raw(self::showKey($card));

        return $v === null || $v === '' ? true : in_array(strtolower($v), ['1', 'true', 'on', 'yes'], true);
    }

    public static function cardOrder(string $card, int $index = 0): int
    {
        $v = self::raw(self::orderKey($card));

        return is_numeric($v) ? max(1, min(999, (int) $v)) : self::defaultOrder($card, $index);
    }

    /** The Super Admin's offer line for a card in the visitor's language (Telugu falls back to English), or null. */
    public static function line(string $card): ?string
    {
        if (SiteText::isTelugu()) {
            $te = trim((string) self::raw(self::lineKey($card, 'te')));
            if ($te !== '') {
                return $te;
            }
        }
        $en = trim((string) self::raw(self::lineKey($card, 'en')));

        return $en !== '' ? $en : null;
    }

    public static function savedLine(string $card, string $locale): string
    {
        return (string) self::raw(self::lineKey($card, $locale));
    }

    public static function forget(): void
    {
        foreach (array_keys(request()->attributes->all()) as $k) {
            if (str_starts_with((string) $k, 'home_cards:')) {
                request()->attributes->remove($k);
            }
        }
    }

    private static function raw(string $key): ?string
    {
        $bag = request()->attributes;
        $memo = 'home_cards:'.$key;
        if (! $bag->has($memo)) {
            $v = Setting::get($key, null);
            $bag->set($memo, $v === null ? null : (string) $v);
        }

        return $bag->get($memo);
    }
}
