<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * The homepage "Explore 1CallFix" doors (owner mockup 2026-10-30): one big tile per business — Services, Parcel,
 * Shop, and later Food, Grocery and the rest. A door shows only where its business is live for the visitor (Admin →
 * Modules); everything else is the Super Admin's (Admin → Growth → Homepage sections): whether the row shows, where,
 * each door on/off, its order and its picture. Words (title, line, live line) are in SiteText, English + Telugu.
 *
 * Live lines use real numbers only (services listed, shops open right now, the parcel base fare). A door whose live
 * number is zero shows its plain line instead; nothing is ever invented.
 */
final class HomeDoors
{
    public const SHOW = 'home.doors.show';
    public const POSITION = 'home.doors.position';
    public const LIVE_INFO = 'home.doors.live_info';
    public const SHOW_PROMISE = 'home.doors.show_promise';

    /** top = first thing under the top bar; after_categories = under the category icons. */
    public const POSITIONS = ['top', 'after_categories'];

    /** door => [default order, built-in picture (our own drawing, used until the Super Admin uploads one)] */
    public const DOORS = [
        'services' => [1, 'images/home/door-services.svg'],
        'parcel' => [2, 'images/home/door-parcel.svg'],
        'shop' => [3, 'images/home/door-shop.svg'],
    ];

    public static function show(): bool
    {
        return self::flag(self::SHOW, true);
    }

    public static function position(): string
    {
        $v = (string) self::raw(self::POSITION);

        return in_array($v, self::POSITIONS, true) ? $v : 'top';
    }

    public static function liveInfo(): bool
    {
        return self::flag(self::LIVE_INFO, true);
    }

    public static function showPromise(): bool
    {
        return self::flag(self::SHOW_PROMISE, true);
    }

    public static function doorShown(string $door): bool
    {
        return self::flag("home.door.{$door}.show", true);
    }

    public static function doorOrder(string $door): int
    {
        $v = self::raw("home.door.{$door}.order");

        return is_numeric($v) ? max(1, min(99, (int) $v)) : self::DOORS[$door][0];
    }

    public static function imageKey(string $door): string
    {
        return "home.door.{$door}.image";
    }

    public static function hasUpload(string $door): bool
    {
        return trim((string) self::raw(self::imageKey($door))) !== '';
    }

    public static function imageUrl(string $door): string
    {
        $path = trim((string) self::raw(self::imageKey($door)));

        return $path !== '' ? Storage::disk('public')->url($path) : asset(self::DOORS[$door][1]);
    }

    /**
     * The doors to draw, in order. $live: door => ['url' => ..., 'count' => ?int, 'price' => ?string] for each
     * business live here; a door not in $live (switched off in Modules) never shows.
     *
     * @param  array<string, array{url: string, count?: ?int, price?: ?string}>  $live
     * @return list<array{key: string, url: string, title: string, line: string, image: string}>
     */
    public static function doors(array $live): array
    {
        $doors = [];
        foreach (array_keys(self::DOORS) as $door) {
            if (! isset($live[$door]) || ! self::doorShown($door)) {
                continue;
            }
            $info = $live[$door];
            $line = SiteText::get("home.door.{$door}.line");
            if (self::liveInfo()) {
                if (($info['count'] ?? 0) > 0) {
                    $line = SiteText::get($info['count'] === 1 ? "home.door.{$door}.live_one" : "home.door.{$door}.live", ['count' => $info['count']]);
                } elseif (! empty($info['price'])) {
                    $line = SiteText::get("home.door.{$door}.live", ['price' => $info['price']]);
                }
            }
            $doors[] = [
                'key' => $door, 'url' => $info['url'], 'order' => self::doorOrder($door),
                'title' => SiteText::get("home.door.{$door}.title"), 'line' => $line, 'image' => self::imageUrl($door),
            ];
        }
        usort($doors, fn ($a, $b) => [$a['order'], $a['key']] <=> [$b['order'], $b['key']]);

        return $doors;
    }

    public static function forget(): void
    {
        foreach (array_keys(request()->attributes->all()) as $k) {
            if (str_starts_with((string) $k, 'home_doors:')) {
                request()->attributes->remove($k);
            }
        }
    }

    private static function raw(string $key): ?string
    {
        $bag = request()->attributes;
        $memo = 'home_doors:'.$key;
        if (! $bag->has($memo)) {
            $v = Setting::get($key, null);
            $bag->set($memo, $v === null ? null : (string) $v);
        }

        return $bag->get($memo);
    }

    private static function flag(string $key, bool $default): bool
    {
        $v = self::raw($key);

        return $v === null || $v === '' ? $default : in_array(strtolower($v), ['1', 'true', 'on', 'yes'], true);
    }
}
