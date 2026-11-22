<?php

namespace App\Services\Marketing;

use App\Models\City;
use App\Support\SavedItems;
use Illuminate\Support\Str;

/**
 * Any item → a ready social post (owner 2026-11-22: "each and every single product can also be posted"). Every public
 * item already draws ♡ Like + Share on its photo with a sealed ticket (name, own-site link, photo; App\Support\
 * SavedItems), so the 📣 button beside them for marketing staff carries the same ticket into Social posts; the
 * partner "Share my business" card uses the same words. Module and city come from the item's address
 * (/nellore/food/…), so a post for a switched-off module is refused like any other.
 */
class ItemPost
{
    /** First address part after the city => Social posts module (ModuleGate::CODES keys). */
    public const SEGMENT_MODULES = [
        'services' => 'service', 'service' => 'service', 'parcel' => 'parcel', 'shop' => 'shop', 'food' => 'food',
        'hotels' => 'hotel', 'events' => 'events', 'halls' => 'rental', 'rentals' => 'rental', 'homes' => 'rental',
        'vehicles' => 'rental', 'rent' => 'listings', 'second-hand' => 'listings',
    ];

    /** @return array{title: string, caption: string, image: ?string, link: string, module: ?string, city_id: ?int}|null */
    public static function fromTicket(string $ticket): ?array
    {
        $item = SavedItems::open($ticket);
        if (! $item) {
            return null;
        }
        $link = (string) $item['u'];
        [$module, $city] = self::place($link);
        $title = Str::limit(trim((string) $item['t']), 100, '');

        return [
            'title' => $title,
            'caption' => self::caption($title, $module, $city?->name),
            'image' => isset($item['i']) && $item['i'] !== '' ? (string) $item['i'] : null,
            'link' => $link,
            'module' => $module,
            'city_id' => $city?->id,
        ];
    }

    /** @return array{0: ?string, 1: ?City} module and city read from a site address like /nellore/food/cake-house */
    public static function place(string $link): array
    {
        $parts = array_values(array_filter(explode('/', (string) parse_url($link, PHP_URL_PATH))));
        $city = isset($parts[0]) ? City::where('slug', $parts[0])->first(['id', 'name', 'slug']) : null;
        $segment = $city ? ($parts[1] ?? null) : ($parts[0] ?? null);

        return [self::SEGMENT_MODULES[$segment] ?? null, $city];
    }

    /** Plain, friendly words; the link (with tracking) is added per platform by SocialPost::shareText(). */
    public static function caption(string $title, ?string $module, ?string $cityName): string
    {
        $where = $cityName ? " in {$cityName}" : '';
        $action = match ($module) {
            'food' => 'Order now on 1CallFix',
            'shop', 'listings' => 'Order now on 1CallFix',
            'hotel' => 'Book your stay on 1CallFix',
            'events' => 'Get your tickets on 1CallFix',
            'rental' => 'Book it on 1CallFix',
            'parcel' => 'Send it with 1CallFix',
            default => 'Book now on 1CallFix',
        };

        return "✨ {$title}{$where}\n\n{$action}: quick, simple and trusted. Tap the link to see it.";
    }
}
