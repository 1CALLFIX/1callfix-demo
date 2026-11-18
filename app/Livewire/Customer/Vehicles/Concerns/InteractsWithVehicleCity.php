<?php

namespace App\Livewire\Customer\Vehicles\Concerns;

use App\Models\City;
use App\Models\Franchise;
use App\Services\Seo\CityContext;
use App\Services\Slug\SlugManager;
use App\Support\RentalKinds;
use App\Support\Seo\CityModules;

/**
 * Vehicles for rent — public pages live under the city (/{city}/vehicles, /{city}/vehicles/{vehicle}), like property.
 * The city must be live, Rental on and "Vehicles" on in Rental Settings; an old city slug 301s with the query string kept.
 */
trait InteractsWithVehicleCity
{
    public City $vehicleCity;

    protected function enterVehicleCity(string $citySlug): bool
    {
        $found = SlugManager::resolveCity($citySlug);
        abort_if($found === null, 404);
        $this->vehicleCity = $found['model'];

        if ($found['via'] === 'redirect') {
            $path = preg_replace('#^/'.preg_quote($citySlug, '#').'(?=/|$)#', '/'.$this->vehicleCity->slug, '/'.ltrim(request()->path(), '/'));
            $query = request()->getQueryString();
            $this->redirect(url($path).($query ? '?'.$query : ''));

            return false;
        }

        abort_unless(app(CityContext::class)->isLive($this->vehicleCity), 404);
        abort_unless(CityModules::rentalKindLiveIn($this->vehicleCity, RentalKinds::VEHICLES), 404);
        app(CityContext::class)->enter($this->vehicleCity);

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
