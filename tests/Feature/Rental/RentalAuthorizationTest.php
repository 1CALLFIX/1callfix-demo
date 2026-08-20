<?php

namespace Tests\Feature\Rental;

use App\Livewire\Equipment\Manage as EquipmentManage;
use App\Livewire\RentalReservations\Manage as RentalReservationsManage;
use App\Livewire\Vehicles\Manage as VehiclesManage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\RentalFixtureHelpers;
use Tests\TestCase;

/** RENTAL MODULE IMPLEMENTATION admin screens -- permission gate + row-level scope, mirroring PropertyRentalAuthorizationTest. */
class RentalAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use RentalFixtureHelpers;

    // ============================== Vehicles\Manage ==============================

    public function test_vehicles_denied_without_permission(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(VehiclesManage::class)->assertForbidden();
    }

    public function test_vehicles_allowed_with_permission(): void
    {
        $actor = $this->makeUserWithPermission('vehicles.manage', 'global');

        Livewire::actingAs($actor)->test(VehiclesManage::class)->assertOk();
    }

    public function test_vehicles_row_level_scope_filters_the_list(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $mine = $this->makeVehicle($franchise, $zone);
        $other = $this->makeVehicleReservationScenario()['vehicle'];

        $actor = $this->makeUserWithPermission('vehicles.manage', 'franchise', $franchise->id);

        $component = Livewire::actingAs($actor)->test(VehiclesManage::class);
        $vehicles = $component->viewData('vehicles');

        $this->assertTrue($vehicles->contains('id', $mine->id));
        $this->assertFalse($vehicles->contains('id', $other->id));
    }

    /**
     * Admin Command Center mission (Security audit) — createVehicle() had no
     * scope check at all: the zones dropdown is unscoped, so a franchise-
     * scoped actor could create a vehicle under a completely different
     * franchise just by picking its zone. edit/saveEdit were already safe.
     */
    public function test_vehicles_create_denied_for_a_different_franchises_zone(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $other = $this->makeVehicleReservationScenario(); // separate franchise/zone
        $category = $this->makeVehicleCategory();
        $owner = $this->makeRentalOwner($franchise, $zone);

        $actor = $this->makeUserWithPermission('vehicles.manage', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(VehiclesManage::class)
            ->set('providerId', $owner->id)
            ->set('vehicleCategoryId', $category->id)
            ->set('zoneId', $other['zone']->id)
            ->set('make', 'Cross-Scope')
            ->set('model', 'Attempt')
            ->set('basePrice', '500')
            ->set('pricingUnit', 'daily')
            ->call('createVehicle')
            ->assertHasErrors('zoneId');

        $this->assertDatabaseMissing('vehicles', ['zone_id' => $other['zone']->id, 'make' => 'Cross-Scope']);
    }

    public function test_vehicles_create_allowed_within_own_franchise_scope(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $category = $this->makeVehicleCategory();
        $owner = $this->makeRentalOwner($franchise, $zone);

        $actor = $this->makeUserWithPermission('vehicles.manage', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(VehiclesManage::class)
            ->set('providerId', $owner->id)
            ->set('vehicleCategoryId', $category->id)
            ->set('zoneId', $zone->id)
            ->set('make', 'In-Scope')
            ->set('model', 'Attempt')
            ->set('basePrice', '500')
            ->set('pricingUnit', 'daily')
            ->call('createVehicle')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('vehicles', ['zone_id' => $zone->id, 'make' => 'In-Scope']);
    }

    // ============================== Equipment\Manage ==============================

    public function test_equipment_denied_without_permission(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(EquipmentManage::class)->assertForbidden();
    }

    public function test_equipment_allowed_with_permission(): void
    {
        $actor = $this->makeUserWithPermission('equipment.manage', 'global');

        Livewire::actingAs($actor)->test(EquipmentManage::class)->assertOk();
    }

    /** Same bug class as Vehicles' create -- see that test's docblock. */
    public function test_equipment_create_denied_for_a_different_franchises_zone(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $other = $this->makeEquipmentReservationScenario();
        $category = $this->makeEquipmentCategory();
        $owner = $this->makeRentalOwner($franchise, $zone);

        $actor = $this->makeUserWithPermission('equipment.manage', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(EquipmentManage::class)
            ->set('providerId', $owner->id)
            ->set('equipmentCategoryId', $category->id)
            ->set('zoneId', $other['zone']->id)
            ->set('name', 'Cross-Scope Attempt')
            ->set('basePrice', '200')
            ->set('pricingUnit', 'daily')
            ->call('createItem')
            ->assertHasErrors('zoneId');

        $this->assertDatabaseMissing('equipment_items', ['zone_id' => $other['zone']->id, 'name' => 'Cross-Scope Attempt']);
    }

    // ============================== RentalReservations\Manage ==============================

    public function test_reservations_denied_without_permission(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(RentalReservationsManage::class)->assertForbidden();
    }

    public function test_reservations_allowed_with_permission(): void
    {
        $actor = $this->makeUserWithPermission('rental_reservations.view', 'global');

        Livewire::actingAs($actor)->test(RentalReservationsManage::class)->assertOk();
    }

    public function test_a_franchise_scoped_viewer_cannot_open_another_franchises_reservation(): void
    {
        $mine = $this->makeVehicleReservationScenario('pending');
        $other = $this->makeVehicleReservationScenario('pending');

        $actor = $this->makeUserWithPermission('rental_reservations.view', 'franchise', $mine['franchise']->id);

        Livewire::actingAs($actor)->test(RentalReservationsManage::class)
            ->call('viewReservation', $other['reservation']->id)
            ->assertStatus(404);
    }

    public function test_row_level_scope_filters_the_reservations_list_across_both_rental_types(): void
    {
        $mine = $this->makeVehicleReservationScenario('pending');
        $mineEquipment = $this->makeEquipmentReservationScenario('pending', ['franchise_id' => $mine['franchise']->id, 'zone_id' => $mine['zone']->id]);
        $other = $this->makeVehicleReservationScenario('pending');

        $actor = $this->makeUserWithPermission('rental_reservations.view', 'franchise', $mine['franchise']->id);

        $component = Livewire::actingAs($actor)->test(RentalReservationsManage::class);
        $reservations = $component->viewData('reservations');

        $this->assertTrue($reservations->contains('id', $mine['reservation']->id));
        $this->assertTrue($reservations->contains('id', $mineEquipment['reservation']->id));
        $this->assertFalse($reservations->contains('id', $other['reservation']->id));
    }

    public function test_cancel_action_requires_rental_reservations_cancel_permission(): void
    {
        $actor = $this->makeUserWithPermission('rental_reservations.view', 'global');
        $scenario = $this->makeVehicleReservationScenario('pending');
        $this->actingAs($actor);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $manage = new RentalReservationsManage();
        $manage->selectedReservationId = $scenario['reservation']->id;
        $manage->cancelReservation(app(\App\Actions\AdminCancelRentalReservationAction::class));
    }
}
