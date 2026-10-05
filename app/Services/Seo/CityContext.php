<?php

namespace App\Services\Seo;

use App\Models\City;
use App\Models\Franchise;
use App\Models\Zone;
use App\Services\Customer\CustomerLocationContext;
use App\Support\Seo\QaRows;
use Illuminate\Support\Collection;

/**
 * F3: "which city is this public page for", and which franchise stands behind it.
 *
 *  - A city is LIVE (has public pages, sitemap entries) when cities.is_active AND at least one franchise in it is
 *    active. QA cities are hidden from public pages on production (QaRows::hiding()) and from sitemaps always.
 *  - Several active franchises in one city: the one in the visitor's session zone wins, else the lowest id.
 *  - The city a link should point at: the session zone's city, else the only live city, else null (chooser).
 *
 * Nothing here is authoritative for price/availability: the franchise is still re-derived from the session zone
 * (CustomerLocationContext). enter() only makes that zone consistent with the city in the URL.
 */
class CityContext
{
    private const MEMO = 'f3.city_context.';

    public function __construct(private CustomerLocationContext $location)
    {
    }

    /** @return Collection<int, City> live cities, ordered by id */
    public function liveCities(): Collection
    {
        return $this->memo('live', fn () => City::query()->live()->orderBy('cities.id')->get()
            ->filter(fn (City $c) => ! (QaRows::hiding() && QaRows::isQaName($c->name)))
            ->values());
    }

    /**
     * Per-request memo (a link grid asks for the city once per card). Kept on the request object, not on this
     * service, so every HTTP / Livewire request starts clean and a test's second request never sees stale data.
     * Tests that change cities between two calls inside ONE request call flush().
     */
    private function memo(string $key, \Closure $compute): mixed
    {
        $bag = app('request')->attributes;
        if (! $bag->has(self::MEMO.$key)) {
            $bag->set(self::MEMO.$key, $compute());
        }

        return $bag->get(self::MEMO.$key);
    }

    public function flush(): void
    {
        foreach (['live', 'current'] as $key) {
            app('request')->attributes->remove(self::MEMO.$key);
        }
    }

    public function isLive(City $city): bool
    {
        return $this->liveCities()->contains('id', $city->id);
    }

    /** The city a generated link should use, or null when the visitor must choose. */
    public function current(): ?City
    {
        return $this->memo('current', fn () => $this->resolveCurrent());
    }

    private function resolveCurrent(): ?City
    {
        $fromZone = $this->location->zone()?->franchise?->city;
        if ($fromZone && $this->isLive($fromZone)) {
            return $this->liveCities()->firstWhere('id', $fromZone->id);
        }

        $live = $this->liveCities();

        return $live->count() === 1 ? $live->first() : null;
    }

    /** The franchise behind a city's pages: the session zone's franchise if it is in this city, else the lowest-id active one. */
    public function franchiseFor(City $city): ?Franchise
    {
        $sessionFranchise = $this->location->zone()?->franchise;
        if ($sessionFranchise && (int) $sessionFranchise->city_id === (int) $city->id && $sessionFranchise->status === 'active') {
            return $sessionFranchise;
        }

        return Franchise::query()->where('city_id', $city->id)->where('status', 'active')->orderBy('id')->first();
    }

    /**
     * Visiting /{city}/... scopes price, availability and providers to that city: if the session zone is not
     * already in it, point the session at the city's franchise's first active zone.
     */
    public function enter(City $city): void
    {
        $zone = $this->location->zone();
        if ($zone && (int) $zone->franchise?->city_id === (int) $city->id) {
            return;
        }

        $franchise = $this->franchiseFor($city);
        $first = $franchise
            ? Zone::query()->where('franchise_id', $franchise->id)->where('is_active', true)->orderBy('id')->first()
            : null;

        if ($first) {
            $this->location->setZone($first->id);
            $this->flush();
        }
    }
}
