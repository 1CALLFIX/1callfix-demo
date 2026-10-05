<?php

namespace Tests\Feature\Support;

use App\Models\City;
use App\Models\Country;
use App\Models\Franchise;
use App\Models\Zone;

/**
 * F3: public catalog pages live at /{city}/{slug}, and a city only has public pages when it is active AND has
 * an active franchise. Tests that open those pages call liveCity() first; with exactly one live city every
 * generated link (PublicUrl) points at it.
 */
trait LiveCity
{
    protected function liveCity(string $name = 'Nellore', ?string $slug = null, bool $withProvider = false): City
    {
        $country = Country::query()->first() ?? Country::create([
            'name' => 'India', 'code' => 'IN', 'currency_code' => 'INR', 'default_timezone' => 'Asia/Kolkata', 'is_active' => true,
        ]);

        $city = City::create(array_filter([
            'country_id' => $country->id, 'name' => $name, 'slug' => $slug, 'is_active' => true,
        ], fn ($v) => $v !== null));

        $franchise = Franchise::create([
            'name' => $name.' Franchise', 'slug' => $city->slug.'-hq', 'city' => $city->name,
            'country_id' => $country->id, 'city_id' => $city->id,
            'commission_model' => 'revenue_share', 'commission_value' => 10, 'platform_fee_percent' => 5,
            'status' => 'active',
        ]);

        Zone::create([
            'franchise_id' => $franchise->id, 'name' => $name.' Zone',
            'boundary_polygon' => [['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2], ['lat' => 3, 'lng' => 3]],
            'is_active' => true, 'default_dispatch_radius_km' => 8,
        ]);

        return $city;
    }

    /** An approved, active provider of the city's franchise holding these category ids (makes pages "real" for indexing). */
    protected function providerFor(City $city, array $categoryIds): \App\Models\Provider
    {
        $franchise = Franchise::where('city_id', $city->id)->first();
        $zone = Zone::where('franchise_id', $franchise->id)->first();
        $user = \App\Models\User::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Provider', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active',
        ]);

        return \App\Models\Provider::create([
            'user_id' => $user->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'provider_type' => 'independent', 'skills' => $categoryIds, 'kyc_status' => 'approved', 'is_active' => true, 'is_online' => false,
        ]);
    }
}
