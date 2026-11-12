<?php

namespace App\Support;

use App\Models\Setting;

/**
 * One colour per business (owner "Go" 2026-11-07, the super-app look: one brand, each module its own colour so people
 * always know where they are). 1CallFix blue stays the brand and the main buttons; the module colour marks the strip
 * under the header, the home door, the module label and the module's own small buttons. Super Admin → Homepage &
 * sign-up → Module colours.
 */
class ModuleTheme
{
    public const DEFAULTS = [
        'services' => '#2563EB', // 1CallFix blue
        'food' => '#E23744',     // tomato red
        'shop' => '#16A34A',     // green
        'parcel' => '#7C3AED',   // purple
        'hotels' => '#0284C7',   // sky blue (Hotels 2026-11-12)
    ];

    public static function key(string $module): string
    {
        return 'theme.module_colour.'.$module;
    }

    public static function colour(string $module): string
    {
        $default = self::DEFAULTS[$module] ?? self::DEFAULTS['services'];
        $bag = request()->attributes;
        $memo = 'module_theme:'.$module;
        if (! $bag->has($memo)) {
            $v = (string) Setting::get(self::key($module), '');
            $bag->set($memo, self::isHex($v) ? strtoupper($v) : $default);
        }

        return $bag->get($memo);
    }

    public static function isHex(string $v): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1;
    }

    /** CSS variables for a module: --mod (the colour), --mod-soft (a light tint) and --mod-ink (darker, for text). */
    public static function style(?string $module): string
    {
        if ($module === null || ! isset(self::DEFAULTS[$module])) {
            return '';
        }
        $hex = self::colour($module);

        return "--mod: {$hex}; --mod-soft: {$hex}1A; --mod-ink: color-mix(in srgb, {$hex} 80%, black);";
    }

    /** The module the current page belongs to, from its route name (null on home, account, orders, …). */
    public static function forRoute(?string $route): ?string
    {
        if ($route === null) {
            return null;
        }

        return match (true) {
            str_starts_with($route, 'customer.food.') => 'food',
            str_starts_with($route, 'customer.hotels.') => 'hotels',
            str_starts_with($route, 'customer.shop.') => 'shop',
            str_starts_with($route, 'customer.parcels.'), $route === 'customer.city.parcel' => 'parcel',
            str_starts_with($route, 'customer.services.'), str_starts_with($route, 'customer.categories.'),
            in_array($route, ['customer.city.services', 'customer.city.item', 'customer.city.legacy'], true) => 'services',
            default => null,
        };
    }
}
