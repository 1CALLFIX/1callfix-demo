<?php

namespace App\Livewire\Customer\Shop\Concerns;

use App\Models\City;
use App\Models\Store;
use App\Services\Seo\CityContext;
use App\Services\Slug\SlugManager;
use App\Support\Modules;

/**
 * E-commerce Phase 2 — the public shop pages live under the city (link rule: /{city}/shop, /{city}/shop/{shop},
 * /{city}/shop/{shop}/{product}). The city must be live and Commerce switched on for it (Admin → Modules); an old
 * city slug 301s to the current one with the query string kept. Shops shown are approved, active and in that city.
 */
trait InteractsWithShopCity
{
    public City $shopCity;

    protected function enterShopCity(string $citySlug): bool
    {
        $found = SlugManager::resolveCity($citySlug);
        abort_if($found === null, 404);
        $this->shopCity = $found['model'];

        if ($found['via'] === 'redirect') {
            $path = preg_replace('#^/'.preg_quote($citySlug, '#').'(?=/|$)#', '/'.$this->shopCity->slug, '/'.ltrim(request()->path(), '/'));
            $query = request()->getQueryString();
            $this->redirect(url($path).($query ? '?'.$query : ''));

            return false;
        }

        abort_unless(app(CityContext::class)->isLive($this->shopCity), 404);
        abort_unless(self::shopLiveIn($this->shopCity), 404);
        app(CityContext::class)->enter($this->shopCity);

        return true;
    }

    public static function shopLiveIn(City $city): bool
    {
        // Commerce can be switched on per city or per franchise (Admin → Modules): live if any franchise here has it.
        return \App\Support\Seo\CityModules::shopLiveIn($city);
    }

    /** Approved, active Commerce shops in this city that a visitor may see. */
    protected function cityStores()
    {
        return self::publicStoresIn($this->shopCity);
    }

    /**
     * City URL architecture: sellable, never a test shop or a QA shop on production. A signed-in test account still
     * sees test shops so the walk-through with the test logins works on the live site.
     */
    public static function publicStoresIn(City $city)
    {
        return Store::query()->publiclyVisible()->where('module', Modules::COMMERCE)
            ->whereHas('franchise', fn ($q) => $q->where('city_id', $city->id));
    }
}
