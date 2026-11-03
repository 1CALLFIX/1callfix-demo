<?php

namespace App\Livewire\Customer\Food\Concerns;

use App\Models\City;
use App\Models\Store;
use App\Services\Seo\CityContext;
use App\Services\Slug\SlugManager;
use App\Support\Modules;
use App\Support\Seo\CityModules;

/**
 * Food F3 — the public food pages live under the city (link rule: /{city}/food, /{city}/food/{restaurant}). The
 * city must be live and Food switched on for it (Admin → Modules); an old city slug 301s to the current one with the
 * query string kept. Restaurants shown are approved, active, in that city and have their FSSAI number when Food
 * Settings needs one. Test restaurants follow the shop rule (Shop Settings → Hide test shops).
 */
trait InteractsWithFoodCity
{
    public City $foodCity;

    protected function enterFoodCity(string $citySlug): bool
    {
        $found = SlugManager::resolveCity($citySlug);
        abort_if($found === null, 404);
        $this->foodCity = $found['model'];

        if ($found['via'] === 'redirect') {
            $path = preg_replace('#^/'.preg_quote($citySlug, '#').'(?=/|$)#', '/'.$this->foodCity->slug, '/'.ltrim(request()->path(), '/'));
            $query = request()->getQueryString();
            $this->redirect(url($path).($query ? '?'.$query : ''));

            return false;
        }

        abort_unless(app(CityContext::class)->isLive($this->foodCity), 404);
        abort_unless(CityModules::foodLiveIn($this->foodCity), 404);
        app(CityContext::class)->enter($this->foodCity);

        return true;
    }

    /** Approved, active restaurants in this city that a visitor may see. */
    public static function publicRestaurantsIn(City $city)
    {
        return Store::query()->publiclyVisible()->where('module', Modules::FOOD)
            ->whereHas('franchise', fn ($q) => $q->where('city_id', $city->id));
    }
}
