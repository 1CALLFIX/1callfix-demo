<?php

namespace App\Livewire\Customer\Events\Concerns;

use App\Models\City;
use App\Models\Event;
use App\Services\Seo\CityContext;
use App\Services\Slug\SlugManager;
use App\Support\Modules;
use App\Support\Seo\CityModules;

/**
 * Events E2 — the public event pages live under the city (/{city}/events, /{city}/events/{event}), like Hotels. The
 * city must be live and Events switched on for it (Admin → Modules); an old city slug 301s with the query string kept.
 * Events shown are approved, from an approved and switched-on organiser, in that city, and not yet over. Test
 * organisers follow the shop rule (Shop Settings → Hide test shops).
 */
trait InteractsWithEventCity
{
    public City $eventCity;

    protected function enterEventCity(string $citySlug): bool
    {
        $found = SlugManager::resolveCity($citySlug);
        abort_if($found === null, 404);
        $this->eventCity = $found['model'];

        if ($found['via'] === 'redirect') {
            $path = preg_replace('#^/'.preg_quote($citySlug, '#').'(?=/|$)#', '/'.$this->eventCity->slug, '/'.ltrim(request()->path(), '/'));
            $query = request()->getQueryString();
            $this->redirect(url($path).($query ? '?'.$query : ''));

            return false;
        }

        abort_unless(app(CityContext::class)->isLive($this->eventCity), 404);
        abort_unless(CityModules::liveIn($this->eventCity, Modules::EVENTS), 404);
        app(CityContext::class)->enter($this->eventCity);

        return true;
    }

    /** Approved events in this city that are not over yet, soonest first. */
    public static function publicEventsIn(City $city)
    {
        $query = Event::query()->where('status', 'approved')->where('ends_at', '>', now())
            ->whereHas('franchise', fn ($q) => $q->where('city_id', $city->id))
            ->whereHas('organiser', fn ($q) => $q->where('approval_status', 'approved')->where('is_active', true));
        if (\App\Support\MarketplaceSettings::on('marketplace.hide_test_shops') && ! \App\Models\Store::isTestViewer(auth()->user())) {
            $query->whereDoesntHave('organiser.provider.user', fn ($q) => $q->where('email', 'like', '%'.\App\Console\Commands\TestAccountsCommand::DOMAIN));
        }

        return $query->orderBy('starts_at');
    }
}
