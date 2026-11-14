<?php

namespace App\Livewire\Customer\Homes\Concerns;

use App\Models\City;
use App\Models\Franchise;
use App\Services\Seo\CityContext;
use App\Services\Slug\SlugManager;
use App\Support\RentalKinds;
use App\Support\Seo\CityModules;

/**
 * Houses for rent — public pages live under the city (/{city}/homes, /{city}/homes/{home}), like halls. The city must be
 * live, Rental on and "Houses for rent" on in Rental Settings; an old city slug 301s with the query string kept.
 */
trait InteractsWithHomeCity
{
    public City $homeCity;

    protected function enterHomeCity(string $citySlug): bool
    {
        $found = SlugManager::resolveCity($citySlug);
        abort_if($found === null, 404);
        $this->homeCity = $found['model'];

        if ($found['via'] === 'redirect') {
            $path = preg_replace('#^/'.preg_quote($citySlug, '#').'(?=/|$)#', '/'.$this->homeCity->slug, '/'.ltrim(request()->path(), '/'));
            $query = request()->getQueryString();
            $this->redirect(url($path).($query ? '?'.$query : ''));

            return false;
        }

        abort_unless(app(CityContext::class)->isLive($this->homeCity), 404);
        abort_unless(CityModules::rentalKindLiveIn($this->homeCity, RentalKinds::PROPERTIES), 404);
        app(CityContext::class)->enter($this->homeCity);

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
