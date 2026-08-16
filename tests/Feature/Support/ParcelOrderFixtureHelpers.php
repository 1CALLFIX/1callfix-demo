<?php

namespace Tests\Feature\Support;

use App\Models\Address;
use App\Models\FieldWorker;
use App\Models\FieldWorkerCapability;
use App\Models\Module;
use App\Models\ParcelOrder;
use App\Models\User;
use App\Services\ModuleActivationService;
use App\Support\Modules;
use Illuminate\Support\Str;

/**
 * Phase 22.4 (Parcel) — the ParcelOrder counterpart to BookingFixtureHelpers,
 * reusing makeFranchiseTree()/makeCustomer() from that trait rather than
 * duplicating them (both traits are meant to be used together).
 */
trait ParcelOrderFixtureHelpers
{
    /**
     * `modules.is_implemented` for `parcel` is `false` in every real
     * migration (deliberately — Phase 22.1's hard gate, not flipped by
     * this phase). Tests that need to exercise real Parcel order-creation
     * code paths call this first, exactly the same way
     * ModuleActivationServiceTest flips it for its own isolated
     * assertions — this is the honest way to test "built but disabled"
     * functionality without changing what ships to production.
     */
    protected function enableParcelModuleForTests(): void
    {
        Module::where('code', Modules::PARCEL)->update(['is_implemented' => true]);
    }

    /**
     * `is_implemented=true` alone is NOT enough to make Parcel orderable —
     * ModuleActivationService::isActive() also requires an explicit
     * activation row for any module other than `service` (the one
     * documented legacy-default exception, Phase 22.1). This helper does
     * both steps together for tests that need a real, order-creatable
     * franchise, exactly the two-step reality a real admin would go
     * through: ship the code (is_implemented), then explicitly turn it on
     * for a location (an activation row) via the Modules screen.
     */
    protected function activateParcelFor($franchise): void
    {
        $this->enableParcelModuleForTests();
        app(ModuleActivationService::class)->setActive(Modules::PARCEL, 'franchise', $franchise->id, true);
    }

    protected function makeParcelAddresses($franchise, $zone, User $customer): array
    {
        $pickup = Address::create([
            'user_id' => $customer->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'label' => 'Pickup', 'lat' => 1.0, 'lng' => 1.0, 'address_line' => 'Pickup Addr',
        ]);
        $dropoff = Address::create([
            'user_id' => $customer->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'label' => 'Dropoff', 'lat' => 1.01, 'lng' => 1.01, 'address_line' => 'Dropoff Addr',
        ]);

        return [$pickup, $dropoff];
    }

    protected function makeParcelRiderIn($franchise, $zone): FieldWorker
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Rider', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
        ]);

        $worker = FieldWorker::create([
            'user_id' => $user->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'kyc_status' => 'approved', 'is_active' => true, 'is_online' => true,
            'current_lat' => 1.0, 'current_lng' => 1.0,
        ]);

        FieldWorkerCapability::create(['field_worker_id' => $worker->id, 'capability_type' => 'parcel_rider']);

        return $worker;
    }

    /**
     * A complete, ready-to-accept parcel order scenario -- franchise/zone,
     * customer+addresses, one eligible rider, and a 'pending' order.
     */
    protected function makeParcelOrderScenario(string $status = 'pending'): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);
        $rider = $this->makeParcelRiderIn($franchise, $zone);

        $order = ParcelOrder::create([
            'code' => 'PTST-'.now()->format('dm').'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id,
            'status' => $status, 'price_quoted' => 100, 'payment_status' => 'pending', 'payment_method' => 'online',
        ]);

        return compact('country', 'city', 'franchise', 'zone', 'customer', 'pickup', 'dropoff', 'rider', 'order');
    }

    protected function makeAssignedParcelOrderScenario(): array
    {
        $scenario = $this->makeParcelOrderScenario('assigned');
        $scenario['order']->update([
            'assigned_worker_id' => $scenario['rider']->id,
            'pickup_otp' => '1234',
            'delivery_otp' => '5678',
        ]);
        $scenario['order'] = $scenario['order']->fresh();

        return $scenario;
    }
}
