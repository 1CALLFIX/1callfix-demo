<?php

namespace App\Livewire\Customer\Halls\Concerns;

use App\Models\City;
use App\Models\Hall;
use App\Services\Seo\CityContext;
use App\Services\Slug\SlugManager;
use App\Support\Modules;
use App\Support\Seo\CityModules;

/**
 * Function halls R2 — the public hall pages live under the city (/{city}/halls, /{city}/halls/{hall}), like Hotels
 * and Events. The city must be live and Function Halls switched on for it (Admin → Modules); an old city slug 301s with
 * the query string kept. Halls shown are approved and switched on, in that city. Test halls follow the shop rule
 * (Shop Settings → Hide test shops).
 */
trait InteractsWithHallCity
{
    public City $hallCity;

    protected function enterHallCity(string $citySlug): bool
    {
        $found = SlugManager::resolveCity($citySlug);
        abort_if($found === null, 404);
        $this->hallCity = $found['model'];

        if ($found['via'] === 'redirect') {
            $path = preg_replace('#^/'.preg_quote($citySlug, '#').'(?=/|$)#', '/'.$this->hallCity->slug, '/'.ltrim(request()->path(), '/'));
            $query = request()->getQueryString();
            $this->redirect(url($path).($query ? '?'.$query : ''));

            return false;
        }

        abort_unless(app(CityContext::class)->isLive($this->hallCity), 404);
        abort_unless(CityModules::hallsLiveIn($this->hallCity), 404);
        app(CityContext::class)->enter($this->hallCity);

        return true;
    }

    /** Approved, switched-on halls in this city. */
    public static function publicHallsIn(City $city)
    {
        $query = Hall::query()->where('status', 'approved')->where('is_active', true)
            ->whereHas('franchise', fn ($q) => $q->where('city_id', $city->id));
        if (\App\Support\MarketplaceSettings::on('marketplace.hide_test_shops') && ! \App\Models\Store::isTestViewer(auth()->user())) {
            $query->whereDoesntHave('provider.user', fn ($q) => $q->where('email', 'like', '%'.\App\Console\Commands\TestAccountsCommand::DOMAIN));
        }

        return $query;
    }
}
