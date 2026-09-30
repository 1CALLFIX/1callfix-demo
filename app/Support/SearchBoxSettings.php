<?php

namespace App\Support;

use App\Models\Setting;

/**
 * REF 1CF-SEARCHBOX-ADMIN-001 — admin-controlled look and rotation of the header search box,
 * stored as plain global Settings rows (no migration). Every getter falls back to the
 * previous hard-coded behaviour, so nothing changes until an admin saves something.
 */
final class SearchBoxSettings
{
    public const SLOTS = 8;

    public const K_SERVICES = 'search_box.services';      // CSV of service ids, rotation order
    public const K_SIZE = 'search_box.text_size';         // small | medium | large
    public const K_COLOR = 'search_box.text_color';       // #RRGGBB or empty (default grey)
    public const K_PREFIX = 'search_box.prefix';          // e.g. "Search for"
    public const K_SECONDS = 'search_box.rotation_seconds';

    /** size key => [label, tailwind class] */
    public const SIZES = [
        'small' => ['Small', 'text-xs'],
        'medium' => ['Medium', 'text-sm'],
        'large' => ['Large', 'text-base'],
    ];

    /** @return array<int, int> service ids in the order the admin set, unique, at most SLOTS */
    public static function serviceIds(): array
    {
        $raw = (string) Setting::get(self::K_SERVICES, '');

        return collect(explode(',', $raw))
            ->map(fn ($v) => (int) trim($v))
            ->filter(fn ($v) => $v > 0)
            ->unique()
            ->take(self::SLOTS)
            ->values()
            ->all();
    }

    public static function size(): string
    {
        $size = (string) Setting::get(self::K_SIZE, 'medium');

        return isset(self::SIZES[$size]) ? $size : 'medium';
    }

    public static function sizeClass(): string
    {
        return self::SIZES[self::size()][1];
    }

    /** A validated #RRGGBB, or null for the default placeholder grey. */
    public static function color(): ?string
    {
        $color = trim((string) Setting::get(self::K_COLOR, ''));

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? strtolower($color) : null;
    }

    public static function prefix(): string
    {
        $prefix = trim((string) Setting::get(self::K_PREFIX, 'Search for'));

        return $prefix !== '' ? mb_substr($prefix, 0, 40) : 'Search for';
    }

    public static function rotationSeconds(): int
    {
        return max(2, min(10, (int) Setting::get(self::K_SECONDS, 3)));
    }
}
