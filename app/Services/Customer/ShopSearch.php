<?php

namespace App\Services\Customer;

use App\Livewire\Customer\Shop\Home as ShopHome;
use App\Models\Product;
use App\Models\Store;
use App\Services\Seo\CityContext;
use App\Support\Modules;
use App\Support\Seo\QaRows;
use Illuminate\Support\Collection;

/**
 * One search (owner 2026-10-30): shop items and shops in the visitor's city, used by the top-bar search box and the
 * search results page. Only where the shop is on in that city (Admin → Modules), and the same rules as the shop
 * pages: switched-on, approved products of approved, active shops; QA shops hidden on production.
 */
class ShopSearch
{
    /**
     * One search for hotels too (owner 2026-11-12: "we already have a search box in the top bar"): bookable hotels in
     * the visitor's city whose name, address (area, landmark) or description matches, where Hotels is on.
     *
     * @return array{city: ?string, hotels: Collection}
     */
    public function hotels(string $term, int $limit): array
    {
        $city = app(CityContext::class)->current();
        $term = trim($term);
        if (! $city || mb_strlen($term) < 2 || ! \App\Support\Seo\CityModules::hotelsLiveIn($city)) {
            return ['city' => null, 'hotels' => collect()];
        }
        $like = '%'.$term.'%';

        return [
            'city' => $city->slug,
            'hotels' => \App\Livewire\Customer\Hotels\Home::publicHotelsIn($city)
                ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('address_line', 'like', $like)->orWhere('description', 'like', $like))
                ->orderByDesc('is_featured')->orderBy('name')->limit($limit)->get(['id', 'name', 'slug', 'address_line']),
        ];
    }

    /**
     * One search for every other module (owner 2026-11-16): restaurants (by name, cuisine or a dish), happenings in the
     * city, function halls and homes for rent. Each only where that module is live in the visitor's city
     * (Admin → Modules), with the same public rules as its own city page.
     *
     * @return array{city: ?string, restaurants: Collection, events: Collection, halls: Collection, homes: Collection}
     */
    public function more(string $term, int $limit): array
    {
        $none = ['city' => null, 'restaurants' => collect(), 'events' => collect(), 'halls' => collect(), 'homes' => collect()];
        $city = app(CityContext::class)->current();
        $term = trim($term);
        if (! $city || mb_strlen($term) < 2) {
            return $none;
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $out = $none;
        $out['city'] = $city->slug;

        if (\App\Support\Seo\CityModules::foodLiveIn($city)) {
            $out['restaurants'] = \App\Livewire\Customer\Food\Home::publicRestaurantsIn($city)
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('cuisines', 'like', $like)
                    ->orWhereHas('products', fn ($p) => $p->where('is_active', true)->where('is_approved', true)->where('name', 'like', $like)))
                ->orderByDesc('is_open')->orderBy('name')->limit($limit)->get(['id', 'name', 'slug', 'cuisines', 'is_open']);
        }
        if (\App\Support\Seo\CityModules::eventsLiveIn($city)) {
            $out['events'] = \App\Livewire\Customer\Events\Home::publicEventsIn($city)
                ->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('venue_name', 'like', $like)->orWhere('category', 'like', $like))
                ->reorder()->orderBy('starts_at')->limit($limit)->get(['id', 'title', 'slug', 'venue_name', 'starts_at']);
        }
        if (\App\Support\Seo\CityModules::hallsLiveIn($city)) {
            $out['halls'] = \App\Livewire\Customer\Halls\Home::publicHallsIn($city)
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('address_line', 'like', $like)->orWhere('hall_type', 'like', $like))
                ->orderByDesc('is_featured')->orderBy('name')->limit($limit)->get(['id', 'name', 'slug', 'address_line']);
        }
        if (\App\Support\Seo\CityModules::rentalKindLiveIn($city, \App\Support\RentalKinds::PROPERTIES)) {
            $out['homes'] = \App\Services\Homes\HouseListingService::publicIn($city)
                ->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('address_line', 'like', $like)->orWhere('landmark', 'like', $like))
                ->orderByDesc('is_featured')->latest('approved_at')->limit($limit)->get(['id', 'title', 'slug', 'address_line']);
        }

        return $out;
    }

    /**
     * `more()` as plain groups the search box and the results page both draw: label, test id, and rows of name / line / url.
     *
     * @param  array{city: ?string, restaurants: Collection, events: Collection, halls: Collection, homes: Collection}  $more
     * @return list<array{key: string, label: string, rows: list<array{name: string, line: ?string, url: string}>}>
     */
    public static function moreGroups(array $more): array
    {
        $c = $more['city'];
        $t = fn (string $k) => \App\Support\SiteText::get($k);
        $groups = [
            ['restaurants', $t('search.restaurants'), $more['restaurants']->map(fn ($r) => ['name' => $r->name, 'line' => $r->cuisines ?: null,
                'url' => route('customer.food.restaurant', [$c, $r->slug])])],
            ['events', $t('search.events'), $more['events']->map(fn ($e) => ['name' => $e->title, 'line' => $e->venue_name ?: null,
                'url' => route('customer.events.show', [$c, $e->slug])])],
            ['halls', $t('search.halls'), $more['halls']->map(fn ($h) => ['name' => $h->name, 'line' => $h->address_line ?: null,
                'url' => route('customer.halls.show', [$c, $h->slug])])],
            ['homes', $t('search.homes'), $more['homes']->map(fn ($h) => ['name' => $h->title, 'line' => $h->address_line ?: null,
                'url' => route('customer.homes.show', [$c, $h->slug])])],
        ];

        return collect($groups)->filter(fn ($g) => $g[2]->isNotEmpty())
            ->map(fn ($g) => ['key' => $g[0], 'label' => $g[1], 'rows' => $g[2]->values()->all()])->values()->all();
    }

    /** @return array{city: ?string, products: Collection, shops: Collection} */
    public function search(string $term, int $productLimit, int $shopLimit): array
    {
        $city = app(CityContext::class)->current();
        if (! $city || mb_strlen(trim($term)) < 2 || ! ShopHome::shopLiveIn($city)) {
            return ['city' => null, 'products' => collect(), 'shops' => collect()];
        }
        $like = '%'.trim($term).'%';
        $stores = fn ($q) => $q->sellable()->where('module', Modules::COMMERCE)
            ->whereHas('franchise', fn ($f) => $f->where('city_id', $city->id))
            ->when(QaRows::hiding(), fn ($s) => $s->where('name', 'not like', QaRows::PREFIX.'%'));

        return [
            'city' => $city->slug,
            'products' => Product::query()->where('is_active', true)->where('is_approved', true)
                ->where('name', 'like', $like)->whereHas('store', $stores)
                ->with('store:id,slug,name')->orderByDesc('stock')->orderBy('name')->limit($productLimit)->get(),
            'shops' => $stores(Store::query())->where('name', 'like', $like)
                ->orderByDesc('is_open')->orderBy('name')->limit($shopLimit)->get(),
        ];
    }
}
