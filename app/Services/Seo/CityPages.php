<?php

namespace App\Services\Seo;

use App\Livewire\Customer\Shop\Home as ShopHome;
use App\Models\City;
use App\Models\Product;
use App\Models\Store;
use App\Services\Parcel\ParcelSettings;
use App\Support\Seo\CityModules;
use App\Support\Seo\QaRows;
use Illuminate\Database\Eloquent\Builder;

/**
 * City URL architecture (2026-11-01): which of a city's pages are real enough to show search engines. The page
 * itself and the sitemap ask the same questions here, so a page is never in the sitemap while saying noindex.
 *
 * Only live cities (CityContext::isLive) and never a "[QA] ..." city. A business page needs real content:
 *  - services: the existing provider-coverage rule (seo.min_providers_to_index), unchanged
 *  - shop:     Commerce on in the city and at least one approved product in a real (non-test, approved) shop
 *  - parcel:   Parcel on in the city and a base fare set for it (a price to show)
 *  - food:     Food on in the city and at least one real restaurant (not a test one, FSSAI in order) with a dish
 *  - hub:      at least one of them
 */
class CityPages
{
    public function __construct(private CityContext $cities, private ProviderCoverage $coverage)
    {
    }

    public function cityIsPublic(City $city): bool
    {
        return $this->cities->isLive($city) && ! QaRows::isQaName($city->name);
    }

    public function servicesIndexable(City $city): bool
    {
        return $this->cityIsPublic($city) && $this->coverage->cityIsReal($city);
    }

    public function shopIndexable(City $city): bool
    {
        return $this->cityIsPublic($city) && CityModules::shopLiveIn($city) && $this->realProducts($city)->exists();
    }

    public function parcelIndexable(City $city): bool
    {
        return $this->cityIsPublic($city) && CityModules::parcelLiveIn($city) && $this->parcelFrom($city) !== null;
    }

    public function hubIndexable(City $city): bool
    {
        return $this->servicesIndexable($city) || $this->shopIndexable($city) || $this->parcelIndexable($city) || $this->foodIndexable($city);
    }

    /** Food F3: the city's food page is real when Food is on there and a real restaurant has a dish. */
    public function foodIndexable(City $city): bool
    {
        return $this->cityIsPublic($city) && CityModules::foodLiveIn($city) && $this->realRestaurants($city)->isNotEmpty();
    }

    /** Restaurants search engines may see: public, not a test or QA one, FSSAI in order, at least one dish. */
    public function realRestaurants(City $city): \Illuminate\Support\Collection
    {
        return \App\Livewire\Customer\Food\Home::publicRestaurantsIn($city)->notTestShop()
            ->when(QaRows::hiding(), fn ($q) => $q->where('name', 'not like', QaRows::LIKE))
            ->get()->filter(fn (Store $s) => $this->restaurantIndexable($s))->values();
    }

    public function restaurantIndexable(Store $store): bool
    {
        return $this->storeIndexable($store) && ! $store->missingFssai();
    }

    /** The city's parcel base fare (Parcel Settings, scoped to the city's franchise), or null when unset. */
    public function parcelFrom(City $city): int|float|null
    {
        $franchise = $this->cities->franchiseFor($city);
        $fare = ParcelSettings::get('parcel.base_fare', array_filter([
            'franchise_id' => $franchise?->id, 'city_id' => $city->id, 'country_id' => $city->country_id,
        ]));

        return is_numeric($fare) && (float) $fare > 0 ? $fare : null;
    }

    /** Shops search engines may see: public, and never a test shop whoever is signed in. */
    public function realStores(City $city): Builder
    {
        return ShopHome::publicStoresIn($city)->notTestShop();
    }

    public function realProducts(City $city): Builder
    {
        return Product::query()->where('is_active', true)->where('is_approved', true)
            ->when(QaRows::hiding(), fn ($q) => $q->where('name', 'not like', QaRows::LIKE))
            ->whereIn('store_id', $this->realStores($city)->select('stores.id'));
    }

    /** True when the shop page may be indexed: a real shop with at least one product. */
    public function storeIndexable(Store $store): bool
    {
        return ! $store->isTestShop() && ! QaRows::isQaName($store->name)
            && $store->products()->where('is_active', true)->where('is_approved', true)->exists();
    }
}
