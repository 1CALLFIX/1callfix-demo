<?php

namespace App\Livewire\Customer\Catalog;

use App\Livewire\Customer\Concerns\ResolvesCatalogContext;
use App\Models\City;
use App\Models\Setting;
use App\Services\Seo\CityPages;
use App\Support\SiteControls;
use App\Support\SiteText;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * City URL architecture (2026-11-01): /{city}/parcel, the public parcel page for a city (404 unless Parcel is on there,
 * Admin → Modules). It explains the service and shows the city's starting price; the button goes to the existing
 * send page (/parcel, sign-in required), which still checks the customer's own pickup address and zone.
 */
class CityParcel extends Component
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
        $from = $pages->parcelFrom($city);

        return view('livewire.customer.catalog.city-parcel', [
            'city' => $city,
            'brand' => $brand,
            'price' => $from !== null ? $this->presenter()->currencySymbol().rtrim(rtrim(number_format((float) $from, 2), '0'), '.') : null,
            'icon' => SiteControls::parcelIconUrl(),
        ])->layout('components.layouts.customer', [
            'title' => SiteText::get('city.parcel.title', ['city' => $city->name]),
            'metaDescription' => SiteText::get('city.parcel.meta', ['city' => $city->name, 'brand' => $brand]),
            'indexable' => $pages->parcelIndexable($city),
        ]);
    }
}
