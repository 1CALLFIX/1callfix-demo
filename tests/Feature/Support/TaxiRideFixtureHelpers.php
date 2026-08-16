<?php

namespace Tests\Feature\Support;

use App\Models\Address;
use App\Models\FieldWorker;
use App\Models\FieldWorkerCapability;
use App\Models\Module;
use App\Models\TaxiRide;
use App\Models\User;
use App\Services\ModuleActivationService;
use App\Support\Modules;
use Illuminate\Support\Str;

/** Phase 22.6 (Taxi) — the TaxiRide counterpart to ParcelOrderFixtureHelpers. */
trait TaxiRideFixtureHelpers
{
    protected function enableTaxiModuleForTests(): void
    {
        Module::where('code', Modules::TAXI)->update(['is_implemented' => true]);
    }

    protected function activateTaxiFor($franchise): void
    {
        $this->enableTaxiModuleForTests();
        app(ModuleActivationService::class)->setActive(Modules::TAXI, 'franchise', $franchise->id, true);
    }

    protected function makeTaxiAddresses($franchise, $zone, User $customer): array
    {
        $pickup = Address::create([
            'user_id' => $customer->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'label' => 'Pickup', 'lat' => 1.0, 'lng' => 1.0, 'address_line' => 'Pickup Addr',
        ]);
        $dropoff = Address::create([
            'user_id' => $customer->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'label' => 'Dropoff', 'lat' => 1.02, 'lng' => 1.02, 'address_line' => 'Dropoff Addr',
        ]);

        return [$pickup, $dropoff];
    }

    protected function makeTaxiDriverIn($franchise, $zone): FieldWorker
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Driver', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
        ]);

        $worker = FieldWorker::create([
            'user_id' => $user->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'kyc_status' => 'approved', 'is_active' => true, 'is_online' => true,
            'current_lat' => 1.0, 'current_lng' => 1.0,
        ]);

        FieldWorkerCapability::create(['field_worker_id' => $worker->id, 'capability_type' => 'taxi_driver']);

        return $worker;
    }

    protected function makeTaxiRideScenario(string $status = 'requested'): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeTaxiAddresses($franchise, $zone, $customer);
        $driver = $this->makeTaxiDriverIn($franchise, $zone);

        $ride = TaxiRide::create([
            'code' => 'TTST-'.now()->format('dm').'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id,
            'status' => $status, 'price_quoted' => 80, 'payment_status' => 'pending', 'payment_method' => 'online',
        ]);

        return compact('country', 'city', 'franchise', 'zone', 'customer', 'pickup', 'dropoff', 'driver', 'ride');
    }

    protected function makeAssignedTaxiRideScenario(): array
    {
        $scenario = $this->makeTaxiRideScenario('assigned');
        $scenario['ride']->update([
            'assigned_worker_id' => $scenario['driver']->id,
            'start_otp' => '1234',
        ]);
        $scenario['ride'] = $scenario['ride']->fresh();

        return $scenario;
    }
}
