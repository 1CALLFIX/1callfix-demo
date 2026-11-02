<?php

namespace App\Support\Seo;

use App\Models\City;
use App\Models\Franchise;
use App\Services\ModuleActivationService;
use App\Support\Modules;

/**
 * City URL architecture (2026-11-01): which businesses are live in a city, read from the existing switches
 * (Admin → Modules, per country / city / franchise / zone). A business is live in a city when it is on for at least
 * one active franchise there. This only decides what a city's pages SHOW (discovery); whether a customer's own
 * address can actually be served is still decided by their zone at booking / checkout time.
 *
 * Also the one list of second URL segments: /{city}/{segment}. The live ones have pages; the future ones are
 * reserved so no city, category or service slug can ever take them.
 */
final class CityModules
{
    /** Second segments with a page today. */
    public const LIVE_SEGMENTS = ['services', 'shop', 'parcel'];

    /** Reserved for businesses that do not have a public city page yet (owner list 2026-11-01). */
    public const FUTURE_SEGMENTS = ['food', 'grocery', 'pharmacy', 'taxi', 'hotels', 'rentals'];

    /** @return list<string> */
    public static function segments(): array
    {
        return array_merge(self::LIVE_SEGMENTS, self::FUTURE_SEGMENTS);
    }

    public static function liveIn(City $city, string $module): bool
    {
        $activation = app(ModuleActivationService::class);
        foreach (Franchise::query()->where('city_id', $city->id)->where('status', 'active')->pluck('id') as $franchiseId) {
            if ($activation->isActive($module, array_filter(['franchise_id' => $franchiseId, 'city_id' => $city->id, 'country_id' => $city->country_id]))) {
                return true;
            }
        }

        return false;
    }

    public static function shopLiveIn(City $city): bool
    {
        return self::liveIn($city, Modules::COMMERCE);
    }

    public static function parcelLiveIn(City $city): bool
    {
        return self::liveIn($city, Modules::PARCEL);
    }
}
