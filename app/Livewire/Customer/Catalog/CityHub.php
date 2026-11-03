<?php

namespace App\Livewire\Customer\Catalog;

use App\Livewire\Customer\Concerns\ResolvesCatalogContext;
use App\Livewire\Customer\Shop\Home as ShopHome;
use App\Models\City;
use App\Models\Setting;
use App\Services\Seo\CityPages;
use App\Support\HomeDoors;
use App\Support\Seo\CityModules;
use App\Support\Seo\PublicUrl;
use App\Support\SiteText;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * City URL architecture (2026-11-01): /{city}, the city hub. One door per business live in the city (Admin → Modules),
 * drawn exactly like the homepage doors (same pictures, order, words and on/off from Admin → Homepage sections).
 * The services list that used to sit here is now /{city}/services. Indexed only when a business here has real
 * content (CityPages::hubIndexable).
 */
class CityHub extends Component
{
    use ResolvesCatalogContext;

    #[Locked]
    public int $cityId;

    public function mount(City $city): void
    {
        $this->cityId = $city->id;
    }

    public function render()
    {
        $city = City::findOrFail($this->cityId);
        $pages = app(CityPages::class);
        $brand = Setting::get('branding.platform_name', '1CallFix');

        $live = [];
        $services = $this->catalog()->services()->count();
        if ($services > 0) {
            $live['services'] = ['url' => PublicUrl::services($city), 'count' => $services];
        }
        if (CityModules::shopLiveIn($city)) {
            $live['shop'] = ['url' => route('customer.shop.home', $city->slug),
                'count' => ShopHome::publicStoresIn($city)->where('is_open', true)->count()];
        }
        if (CityModules::foodLiveIn($city)) {
            $live['food'] = ['url' => route('customer.food.home', $city->slug), 'count' => \App\Livewire\Customer\Food\Home::openCountIn($city)];
        }
        if (CityModules::parcelLiveIn($city)) {
            $from = $pages->parcelFrom($city);
            $live['parcel'] = ['url' => route('customer.city.parcel', $city->slug),
                'price' => $from !== null ? $this->presenter()->currencySymbol().rtrim(rtrim(number_format((float) $from, 2), '0'), '.') : null];
        }

        return view('livewire.customer.catalog.city-hub', [
            'city' => $city,
            'doors' => HomeDoors::doors($live),
            'brand' => $brand,
        ])->layout('components.layouts.customer', [
            'title' => SiteText::get('city.hub.title', ['brand' => $brand, 'city' => $city->name]),
            'metaDescription' => SiteText::get('city.hub.meta', ['brand' => $brand, 'city' => $city->name]),
            'indexable' => $pages->hubIndexable($city),
        ]);
    }
}
