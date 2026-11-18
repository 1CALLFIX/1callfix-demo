<?php

namespace App\Livewire\Customer\RentItems\Concerns;

use App\Models\City;
use App\Models\Franchise;
use App\Services\Seo\CityContext;
use App\Services\Slug\SlugManager;
use App\Support\RentalKinds;
use App\Support\Seo\CityModules;

/**
 * Rent anything (owner decision 2026-11-18): public pages live under the city (/{city}/rent, /{city}/rent/{item}).
 * The city must be live, and Rental and "Rent anything" on in at least one of its zones; an old city slug 301s with the query string kept.
 */
trait InteractsWithItemCity
{
    public City $itemCity;

    protected function enterItemCity(string $citySlug): bool
    {
        $found = SlugManager::resolveCity($citySlug);
        abort_if($found === null, 404);
        $this->itemCity = $found['model'];

        if ($found['via'] === 'redirect') {
            $path = preg_replace('#^/'.preg_quote($citySlug, '#').'(?=/|$)#', '/'.$this->itemCity->slug, '/'.ltrim(request()->path(), '/'));
            $query = request()->getQueryString();
            $this->redirect(url($path).($query ? '?'.$query : ''));

            return false;
        }

        abort_unless(app(CityContext::class)->isLive($this->itemCity), 404);
        abort_unless(CityModules::rentalKindLiveIn($this->itemCity, RentalKinds::ITEMS), 404);
        app(CityContext::class)->enter($this->itemCity);

        return true;
    }

    /** Settings scope for the city's lists (its first active franchise, the city, the country). */
    public static function cityScope(City $city): array
    {
        return array_filter([
            'franchise_id' => Franchise::where('city_id', $city->id)->where('status', 'active')->orderBy('id')->value('id'),
            'city_id' => $city->id, 'country_id' => $city->country_id,
        ]);
    }
}
