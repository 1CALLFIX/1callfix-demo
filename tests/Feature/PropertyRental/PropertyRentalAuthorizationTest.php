<?php

namespace Tests\Feature\PropertyRental;

use App\Livewire\Properties\Manage as PropertiesManage;
use App\Livewire\PropertyReservations\Manage as PropertyReservationsManage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\PropertyRentalFixtureHelpers;
use Tests\TestCase;

/** Phase 22.7 (Property Rental) admin screens — permission gate + row-level scope, mirroring Parcel/Taxi's own authorization tests. */
class PropertyRentalAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use PropertyRentalFixtureHelpers;

    // ============================== Properties\Manage ==============================

    public function test_properties_denied_without_permission(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(PropertiesManage::class)->assertForbidden();
    }

    public function test_properties_allowed_with_permission(): void
    {
        $actor = $this->makeUserWithPermission('properties.manage', 'global');

        Livewire::actingAs($actor)->test(PropertiesManage::class)->assertOk();
    }

    public function test_properties_row_level_scope_filters_the_list(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $mine = $this->makeProperty($franchise, $zone);
        $other = $this->makePropertyReservationScenario()['property'];

        $actor = $this->makeUserWithPermission('properties.manage', 'franchise', $franchise->id);

        $component = Livewire::actingAs($actor)->test(PropertiesManage::class);
        $properties = $component->viewData('properties');

        $this->assertTrue($properties->contains('id', $mine->id));
        $this->assertFalse($properties->contains('id', $other->id));
    }

    /**
     * Admin Command Center mission (Security audit) — createProperty() had
     * no scope check at all: the zones dropdown is unscoped, so a
     * franchise-scoped actor could create a property under a completely
     * different franchise just by picking its zone. edit/saveEdit were
     * already safe (scopedPropertiesQuery() 404s out-of-scope).
     */
    public function test_properties_create_denied_for_a_different_franchises_zone(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $other = $this->makePropertyReservationScenario();
        $type = $this->makePropertyType();
        $owner = $this->makePropertyOwner($franchise, $zone);

        $actor = $this->makeUserWithPermission('properties.manage', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(PropertiesManage::class)
            ->set('providerId', $owner->id)
            ->set('propertyTypeId', $type->id)
            ->set('zoneId', $other['zone']->id)
            ->set('name', 'Cross-Scope Attempt')
            ->set('addressLine', '1 Nowhere St')
            ->set('lat', 1.0)
            ->set('lng', 1.0)
            ->set('basePrice', '500')
            ->call('createProperty')
            ->assertHasErrors('zoneId');

        $this->assertDatabaseMissing('properties', ['zone_id' => $other['zone']->id, 'name' => 'Cross-Scope Attempt']);
    }

    // ============================== PropertyReservations\Manage ==============================

    public function test_reservations_denied_without_permission(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(PropertyReservationsManage::class)->assertForbidden();
    }

    public function test_reservations_allowed_with_permission(): void
    {
        $actor = $this->makeUserWithPermission('property_reservations.view', 'global');

        Livewire::actingAs($actor)->test(PropertyReservationsManage::class)->assertOk();
    }

    public function test_a_franchise_scoped_viewer_cannot_open_another_franchises_reservation(): void
    {
        $mine = $this->makePropertyReservationScenario('pending');
        $other = $this->makePropertyReservationScenario('pending');

        $actor = $this->makeUserWithPermission('property_reservations.view', 'franchise', $mine['franchise']->id);

        Livewire::actingAs($actor)->test(PropertyReservationsManage::class)
            ->call('viewReservation', $other['reservation']->id)
            ->assertStatus(404);
    }

    public function test_row_level_scope_filters_the_reservations_list(): void
    {
        $mine = $this->makePropertyReservationScenario('pending');
        $other = $this->makePropertyReservationScenario('pending');

        $actor = $this->makeUserWithPermission('property_reservations.view', 'franchise', $mine['franchise']->id);

        $component = Livewire::actingAs($actor)->test(PropertyReservationsManage::class);
        $reservations = $component->viewData('reservations');

        $this->assertTrue($reservations->contains('id', $mine['reservation']->id));
        $this->assertFalse($reservations->contains('id', $other['reservation']->id));
    }

    public function test_cancel_action_requires_property_reservations_cancel_permission(): void
    {
        $actor = $this->makeUserWithPermission('property_reservations.view', 'global');
        $scenario = $this->makePropertyReservationScenario('pending');
        $this->actingAs($actor);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $manage = new PropertyReservationsManage();
        $manage->selectedReservationId = $scenario['reservation']->id;
        $manage->cancelReservation(app(\App\Actions\AdminCancelPropertyReservationAction::class));
    }
}
