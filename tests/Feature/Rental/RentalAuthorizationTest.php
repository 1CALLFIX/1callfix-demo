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
