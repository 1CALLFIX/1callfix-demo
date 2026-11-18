<?php

namespace App\Livewire\Customer\Vehicles;

use App\Livewire\Customer\Vehicles\Concerns\InteractsWithVehicleCity;
use App\Models\Setting;
use App\Models\VehicleListing;
use App\Models\Zone;
use App\Services\Vehicles\VehicleListingService;
use App\Support\SiteText;
use App\Support\VehicleListingSettings;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Vehicles for rent — /{city}/vehicles: every owner vehicle live in the city. Tap filters (kind, self-drive / with
 * driver, fuel, area) come from Rental Settings for this city. Featured first, then the newest; or cheapest first.
 */
class Home extends Component
{
    use InteractsWithVehicleCity;

    #[Url(as: 'kind')]
    public string $kind = '';

    #[Url(as: 'how')]
    public string $how = '';

    #[Url(as: 'fuel')]
    public string $fuel = '';

    #[Url(as: 'area')]
    public string $area = '';

    #[Url(as: 'sort')]
    public string $sort = '';

    public function mount(string $city): void
    {
        $this->enterVehicleCity($city);
    }

    public function clearFilters(): void
    {
        $this->reset('kind', 'how', 'fuel', 'area', 'sort');
    }

    /** One vehicle card's words, shared with the Rentals page. */
    public static function card(VehicleListing $vehicle, string $citySlug): array
    {
        $symbol = Setting::get('locale.currency_symbol', '₹');

        return [
            'url' => route('customer.vehicles.show', [$citySlug, $vehicle->slug]),
            'price' => $vehicle->price_day ? SiteText::get('vehicles.card.per_day', ['price' => $symbol.number_format((float) $vehicle->price_day)]) : null,
            'what' => trim(implode(' · ', array_filter([$vehicle->vehicle_kind, $vehicle->fuel, $vehicle->seats ? SiteText::get('vehicles.card.seats', ['count' => $vehicle->seats]) : null]))),
            'where' => trim(implode(' · ', array_filter([$vehicle->zone?->name, $vehicle->landmark]))),
            'how' => implode(' · ', (array) ($vehicle->driver_options ?? [])),
            'cover' => $vehicle->coverUrl(),
        ];
    }

    public function render()
    {
        $city = $this->vehicleCity;
        $scope = self::cityScope($city);
        $base = VehicleListingService::publicIn($city);
        $vehicles = (clone $base)->with(['zone', 'franchise'])
            ->when($this->kind !== '', fn ($q) => $q->where('vehicle_kind', $this->kind))
            ->when($this->how !== '', fn ($q) => $q->whereJsonContains('driver_options', $this->how))
            ->when($this->fuel !== '', fn ($q) => $q->where('fuel', $this->fuel))
            ->when(ctype_digit($this->area), fn ($q) => $q->where('zone_id', (int) $this->area))
            ->when($this->sort === 'cheap', fn ($q) => $q->orderBy('price_day'), fn ($q) => $q->orderByDesc('is_featured')->latest('approved_at'))
            ->limit(60)->get();
        $areaIds = (clone $base)->distinct()->pluck('zone_id')->filter();
        $brand = Setting::get('branding.platform_name', '1CallFix');

        return view('livewire.customer.vehicles.home', [
            'city' => $city,
            'vehicles' => $vehicles,
            'cards' => $vehicles->mapWithKeys(fn (VehicleListing $v) => [$v->id => self::card($v, $city->slug)]),
            'kinds' => VehicleListingSettings::listIn('vehicles.kinds', $scope),
            'hows' => VehicleListingSettings::listIn('vehicles.driver_options', $scope),
            'fuels' => VehicleListingSettings::listIn('vehicles.fuels', $scope),
            'areas' => Zone::whereIn('id', $areaIds)->orderBy('name')->pluck('name', 'id'),
            'filtered' => ($this->kind.$this->how.$this->fuel.$this->area) !== '',
        ])->layout('components.layouts.customer', [
            'title' => SiteText::get('vehicles.meta.title', ['city' => $city->name]),
            'metaDescription' => SiteText::get('vehicles.meta.description', ['city' => $city->name, 'brand' => $brand]),
            'indexable' => true,
        ]);
    }
}
