<?php

namespace Tests\Feature\Support;

use App\Models\Module;
use App\Models\Property;
use App\Models\PropertyReservation;
use App\Models\PropertyType;
use App\Models\Provider;
use App\Models\User;
use App\Services\ModuleActivationService;
use App\Support\Modules;
use Illuminate\Support\Str;

/** Phase 22.7 (Property Rental) — the PropertyReservation counterpart to ParcelOrderFixtureHelpers/TaxiRideFixtureHelpers. */
trait PropertyRentalFixtureHelpers
{
    protected function enableCarRentalModuleForTests(): void
    {
        Module::where('code', Modules::CAR_RENTAL)->update(['is_implemented' => true]);
    }

    protected function activateCarRentalFor($franchise): void
    {
        $this->enableCarRentalModuleForTests();
        app(ModuleActivationService::class)->setActive(Modules::CAR_RENTAL, 'franchise', $franchise->id, true);
    }

    protected function makePropertyType(): PropertyType
    {
        return PropertyType::create(['name' => 'Apartment '.Str::random(4), 'slug' => 'apt-'.Str::random(8), 'is_active' => true]);
    }

    protected function makePropertyOwner($franchise, $zone): Provider
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Owner', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id,
        ]);

        return Provider::create([
            'user_id' => $user->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true,
        ]);
    }

    protected function makeProperty($franchise, $zone, ?Provider $owner = null, array $overrides = []): Property
    {
        $owner ??= $this->makePropertyOwner($franchise, $zone);
        $type = $this->makePropertyType();

        return Property::create(array_merge([
            'provider_id' => $owner->id, 'property_type_id' => $type->id,
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'name' => 'Test Property '.Str::random(6),
            'address_line' => '123 Test Street', 'lat' => 1.0, 'lng' => 1.0,
            'base_price' => 1000, 'max_guests' => 4, 'bedrooms' => 2, 'bathrooms' => 1, 'beds' => 2,
            'minimum_nights' => 1, 'is_active' => true,
        ], $overrides));
    }

    protected function makePropertyReservationScenario(string $status = 'pending'): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $owner = $this->makePropertyOwner($franchise, $zone);
        $property = $this->makeProperty($franchise, $zone, $owner);

        $reservation = PropertyReservation::create([
            'code' => 'RTST-'.now()->format('dm').'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'property_id' => $property->id,
            'check_in_date' => now()->addDays(5)->toDateString(),
            'check_out_date' => now()->addDays(7)->toDateString(),
            'number_of_nights' => 2, 'number_of_guests' => 2,
            'status' => $status, 'price_quoted' => 2000, 'payment_status' => 'pending', 'payment_method' => 'online',
        ]);

        return compact('country', 'city', 'franchise', 'zone', 'customer', 'owner', 'property', 'reservation');
    }
}
