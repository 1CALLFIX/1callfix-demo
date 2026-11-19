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
    public const LIVE_SEGMENTS = ['services', 'shop', 'parcel', 'food', 'hotels', 'events', 'halls', 'rentals', 'property', 'vehicles', 'rent', 'second-hand'];

    /** Other words people type for a live page: 301 to it, query string kept (Second-hand 2026-11-19: /used). */
    public const ALIAS_SEGMENTS = ['used' => 'second-hand'];

    /** Reserved for businesses that do not have a public city page yet (owner list 2026-11-01). */
    public const FUTURE_SEGMENTS = ['grocery', 'pharmacy', 'taxi'];

    /** @return list<string> */
    public static function segments(): array
    {
        return array_merge(self::LIVE_SEGMENTS, array_keys(self::ALIAS_SEGMENTS), self::FUTURE_SEGMENTS);
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

    /** Food F3: /{city}/food. */
    /** Hotels (2026-11-12): the Hotels door, the city hub and the sitemap. */
    public static function hotelsLiveIn(City $city): bool
    {
        return self::liveIn($city, Modules::HOTEL);
    }

    /** Events E2 (2026-11-13): /{city}/events. */
    public static function eventsLiveIn(City $city): bool
    {
        return self::liveIn($city, Modules::EVENTS);
    }

    /** Function halls R2 (2026-11-13): /{city}/halls. One Rental (2026-11-14): Rental on and Function halls on. */
    public static function hallsLiveIn(City $city): bool
    {
        return self::rentalKindLiveIn($city, \App\Support\RentalKinds::HALLS);
    }

    /**
     * One Rental (owner 2026-11-14): Rental on in Modules and this kind on in Rental Settings, for some franchise OR
     * some zone of the city (2026-11-18: a switch set for one zone only counts too).
     */
    public static function rentalKindLiveIn(City $city, string $kind): bool
    {
        return self::rentalKindScopesIn($city, $kind)['live'];
    }

    /**
     * Where this rental kind is on in the city (2026-11-18). `zones`: every zone of an active franchise where it is on
     * (zone → franchise → city → country → global, the same chain a listing's own page and "Show owner's number" use).
     * `franchises`: active franchises where it is on at franchise level, only for a listing whose zone was deleted
     * (zone_id is nulled on delete). `live`: the city pages open only when the kind is on in at least one zone. Every
     * franchise works through its zone(s) (owner 2026-11-18: one zone per franchise), so a franchise with no zone yet,
     * or whose zones are all switched off, opens nothing.
     *
     * @return array{zones: list<int>, franchises: list<int>, live: bool}
     */
    public static function rentalKindScopesIn(City $city, string $kind): array
    {
        return self::scopesIn($city, fn (array $scope) => \App\Support\RentalKinds::active($kind, $scope));
    }

    /**
     * Where a type of people's post is on in the city (2026-11-19): rent = "Rent anything" (a rental kind), sell =
     * Second-hand (its own switch). Same zone-by-zone rule as the rental kinds.
     *
     * @return array{zones: list<int>, franchises: list<int>, live: bool}
     */
    public static function postTypeScopesIn(City $city, string $deal): array
    {
        return self::scopesIn($city, fn (array $scope) => \App\Support\PostTypes::onIn($deal, $scope));
    }

    /** Second-hand (2026-11-19): /{city}/second-hand opens when it is on in at least one zone of the city. */
    public static function secondHandLiveIn(City $city): bool
    {
        return self::postTypeScopesIn($city, \App\Support\PostTypes::SELL)['live'];
    }

    /**
     * The zones (and franchises) of the city's active franchises where $on(scope) is true; `live` once any zone is.
     *
     * @param  callable(array): bool  $on
     * @return array{zones: list<int>, franchises: list<int>, live: bool}
     */
    private static function scopesIn(City $city, callable $on): array
    {
        $zones = [];
        $franchises = [];
        foreach (Franchise::query()->where('city_id', $city->id)->where('status', 'active')->pluck('id') as $franchiseId) {
            $scope = array_filter(['franchise_id' => $franchiseId, 'city_id' => $city->id, 'country_id' => $city->country_id]);
            if ($on($scope)) {
                $franchises[] = (int) $franchiseId;
            }
            foreach (\App\Models\Zone::query()->where('franchise_id', $franchiseId)->pluck('id') as $zoneId) {
                if ($on(['zone_id' => $zoneId] + $scope)) {
                    $zones[] = (int) $zoneId;
                }
            }
        }

        return ['zones' => $zones, 'franchises' => $franchises, 'live' => $zones !== []];
    }

    /**
     * Keep only listings whose own zone (or, with no zone, own franchise) has this rental kind on (2026-11-18). Before
     * this a city page listed every approved listing of the city once ANY franchise had the kind on.
     */
    public static function whereRentalKindOn(\Illuminate\Database\Eloquent\Builder $query, City $city, string $kind): \Illuminate\Database\Eloquent\Builder
    {
        return self::whereScopes($query, self::rentalKindScopesIn($city, $kind));
    }

    /** The same filter for a type of people's post (2026-11-19). */
    public static function wherePostTypeOn(\Illuminate\Database\Eloquent\Builder $query, City $city, string $deal): \Illuminate\Database\Eloquent\Builder
    {
        return self::whereScopes($query, self::postTypeScopesIn($city, $deal));
    }

    /** @param  array{zones: list<int>, franchises: list<int>}  $scopes */
    private static function whereScopes(\Illuminate\Database\Eloquent\Builder $query, array $scopes): \Illuminate\Database\Eloquent\Builder
    {
        $model = $query->getModel();

        return $query->where(fn ($q) => $q->whereIn($model->qualifyColumn('zone_id'), $scopes['zones'])
            ->orWhere(fn ($q) => $q->whereNull($model->qualifyColumn('zone_id'))->whereIn($model->qualifyColumn('franchise_id'), $scopes['franchises'])));
    }

    public static function foodLiveIn(City $city): bool
    {
        return self::liveIn($city, Modules::FOOD);
    }
}
