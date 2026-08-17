<?php

namespace Tests\Feature\Support;

use App\Models\EquipmentCategory;
use App\Models\EquipmentItem;
use App\Models\Module;
use App\Models\Provider;
use App\Models\RentalReservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Services\ModuleActivationService;
use App\Support\Modules;
use Illuminate\Support\Str;

/** RENTAL MODULE IMPLEMENTATION -- the shared Vehicle/Equipment engine counterpart to PropertyRentalFixtureHelpers. */
trait RentalFixtureHelpers
{
    protected function enableRentalModuleForTests(): void
    {
        Module::where('code', Modules::RENTAL)->update(['is_implemented' => true]);
    }

    protected function activateRentalFor($franchise): void
    {
        $this->enableRentalModuleForTests();
        app(ModuleActivationService::class)->setActive(Modules::RENTAL, 'franchise', $franchise->id, true);
    }

    protected function makeRentalOwner($franchise, $zone): Provider
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Rental Owner', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id,
        ]);

        return Provider::create([
            'user_id' => $user->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true,
        ]);
    }

    protected function makeVehicleCategory(array $overrides = []): VehicleCategory
    {
        return VehicleCategory::create(array_merge([
            'name' => 'Car', 'slug' => 'car-'.Str::random(8), 'is_active' => true,
        ], $overrides));
    }

    protected function makeVehicle($franchise, $zone, ?Provider $owner = null, array $overrides = []): Vehicle
    {
        $owner ??= $this->makeRentalOwner($franchise, $zone);
        $category = $overrides['vehicle_category_id'] ?? null;
        unset($overrides['vehicle_category_id']);

        return Vehicle::create(array_merge([
            'provider_id' => $owner->id,
            'vehicle_category_id' => $category ?? $this->makeVehicleCategory()->id,
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'make' => 'Test', 'model' => 'Model '.Str::random(4),
            'supported_rental_modes' => ['self_drive'],
            'base_price' => 500, 'pricing_unit' => 'daily', 'is_active' => true,
        ], $overrides));
    }

    protected function makeEquipmentCategory(array $overrides = []): EquipmentCategory
    {
        return EquipmentCategory::create(array_merge([
            'name' => 'Generator', 'slug' => 'generator-'.Str::random(8), 'is_active' => true,
        ], $overrides));
    }

    protected function makeEquipmentItem($franchise, $zone, ?Provider $owner = null, array $overrides = []): EquipmentItem
    {
        $owner ??= $this->makeRentalOwner($franchise, $zone);
        $category = $overrides['equipment_category_id'] ?? null;
        unset($overrides['equipment_category_id']);

        return EquipmentItem::create(array_merge([
            'provider_id' => $owner->id,
            'equipment_category_id' => $category ?? $this->makeEquipmentCategory()->id,
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'name' => 'Test Equipment '.Str::random(4),
            'base_price' => 200, 'pricing_unit' => 'daily', 'is_active' => true,
        ], $overrides));
    }

    protected function makeVehicleReservationScenario(string $status = 'pending', array $overrides = []): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $owner = $this->makeRentalOwner($franchise, $zone);
        $vehicle = $this->makeVehicle($franchise, $zone, $owner, ['base_price' => 500]);

        $reservation = RentalReservation::create(array_merge([
            'code' => 'RVTST-'.now()->format('dm').'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'rental_type' => 'vehicle', 'rentable_type' => Vehicle::class, 'rentable_id' => $vehicle->id,
            'rental_mode' => 'self_drive',
            'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(7),
            'duration_unit' => 'daily', 'duration_quantity' => 2,
            'status' => $status, 'price_quoted' => 1000, 'payment_status' => 'pending', 'payment_method' => 'online',
        ], $overrides));

        return compact('country', 'city', 'franchise', 'zone', 'customer', 'owner', 'vehicle', 'reservation');
    }

    protected function makeEquipmentReservationScenario(string $status = 'pending', array $overrides = []): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $owner = $this->makeRentalOwner($franchise, $zone);
        $item = $this->makeEquipmentItem($franchise, $zone, $owner, ['base_price' => 200]);

        $reservation = RentalReservation::create(array_merge([
            'code' => 'RETST-'.now()->format('dm').'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'rental_type' => 'equipment', 'rentable_type' => EquipmentItem::class, 'rentable_id' => $item->id,
            'rental_mode' => null,
            'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(7),
            'duration_unit' => 'daily', 'duration_quantity' => 2,
            'status' => $status, 'price_quoted' => 400, 'payment_status' => 'pending', 'payment_method' => 'online',
        ], $overrides));

        return compact('country', 'city', 'franchise', 'zone', 'customer', 'owner', 'item', 'reservation');
    }
}
