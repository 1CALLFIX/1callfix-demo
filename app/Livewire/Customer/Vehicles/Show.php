<?php

namespace App\Livewire\Customer\Vehicles;

use App\Livewire\Customer\Vehicles\Concerns\InteractsWithVehicleCity;
use App\Models\Setting;
use App\Services\Vehicles\VehicleListingService;
use App\Support\SiteText;
use Livewire\Component;

/**
 * Vehicles for rent — /{city}/vehicles/{vehicle}: every photo (♡ Like + Share), price per day and hour, deposit, free km,
 * kind, fuel, gears, seats, year, self-drive / with driver, features, the owner's rules, about, the area and landmark
 * (never the pickup address). The contact panel (the owner's number, call, WhatsApp) loads on its own request, so this
 * page stays public and cacheable.
 */
class Show extends Component
{
    use InteractsWithVehicleCity;

    public string $vehicleSlug = '';

    public function mount(string $city, string $vehicleSlug): void
    {
        if (! $this->enterVehicleCity($city)) {
            return;
        }
        $this->vehicleSlug = $vehicleSlug;
        abort_unless(VehicleListingService::publicIn($this->vehicleCity)->where('slug', $vehicleSlug)->exists(), 404);
    }

    public function render()
    {
        $vehicle = VehicleListingService::publicIn($this->vehicleCity)->where('slug', $this->vehicleSlug)->with(['zone', 'franchise'])->firstOrFail();
        $symbol = Setting::get('locale.currency_symbol', '₹');
        $money = fn ($v) => $v ? $symbol.number_format((float) $v) : null;
        $t = fn ($k, $r = []) => SiteText::get($k, $r);

        return view('livewire.customer.vehicles.show', [
            'vehicle' => $vehicle,
            'city' => $this->vehicleCity,
            'card' => Home::card($vehicle, $this->vehicleCity->slug),
            'photos' => array_values(array_filter(array_merge([$vehicle->coverUrl()], $vehicle->photoUrls()))),
            'facts' => array_values(array_filter([
                [$t('vehicles.show.per_day'), $money($vehicle->price_day), 'bg-sky-50 text-sky-900'],
                $vehicle->price_hour ? [$t('vehicles.show.per_hour'), $money($vehicle->price_hour), 'bg-emerald-50 text-emerald-900'] : null,
                $vehicle->deposit ? [$t('vehicles.show.deposit'), $money($vehicle->deposit), 'bg-violet-50 text-violet-900'] : null,
                [$t('vehicles.show.km'), $vehicle->km_per_day ? number_format($vehicle->km_per_day).' km' : $t('vehicles.show.no_limit'), 'bg-amber-50 text-amber-900'],
                [$t('vehicles.show.kind'), $vehicle->vehicle_kind, 'bg-pink-50 text-pink-900'],
                [$t('vehicles.show.fuel'), $vehicle->fuel, 'bg-teal-50 text-teal-900'],
                $vehicle->transmission ? [$t('vehicles.show.gears'), $vehicle->transmission, 'bg-orange-50 text-orange-900'] : null,
                $vehicle->seats ? [$t('vehicles.show.seats'), (string) $vehicle->seats, 'bg-indigo-50 text-indigo-900'] : null,
                $vehicle->year ? [$t('vehicles.show.year'), (string) $vehicle->year, 'bg-lime-50 text-lime-900'] : null,
            ])),
            'schema' => array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                'name' => $vehicle->title,
                'description' => $vehicle->about,
                'image' => $vehicle->coverUrl() ? [$vehicle->coverUrl()] : null,
                'brand' => $vehicle->make ? ['@type' => 'Brand', 'name' => $vehicle->make] : null,
                'offers' => $vehicle->price_day ? ['@type' => 'Offer', 'price' => (string) (float) $vehicle->price_day, 'priceCurrency' => 'INR', 'availability' => 'https://schema.org/InStock'] : null,
            ]),
        ])->layout('components.layouts.customer', [
            'title' => $vehicle->title.' · '.$this->vehicleCity->name,
            'metaDescription' => \Illuminate\Support\Str::limit(trim($vehicle->title.'. '.$money($vehicle->price_day).' per day. '.implode(', ', (array) $vehicle->driver_options).'. '.$vehicle->about), 155),
            'indexable' => true,
        ]);
    }
}
