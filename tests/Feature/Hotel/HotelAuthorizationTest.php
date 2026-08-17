<?php

namespace Tests\Feature\Hotel;

use App\Livewire\Accommodations\Manage as AccommodationsManage;
use App\Livewire\HotelReservations\Manage as HotelReservationsManage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\HotelFixtureHelpers;
use Tests\TestCase;

/** HOTEL / STAY BOOKING MODULE admin screens — permission gate + row-level scope, mirroring Property Rental's own authorization tests. */
class HotelAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use HotelFixtureHelpers;

    // ============================== Accommodations\Manage ==============================

    public function test_accommodations_denied_without_permission(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(AccommodationsManage::class)->assertForbidden();
    }

    public function test_accommodations_allowed_with_permission(): void
    {
        $actor = $this->makeUserWithPermission('accommodations.manage', 'global');

        Livewire::actingAs($actor)->test(AccommodationsManage::class)->assertOk();
    }

    public function test_accommodations_row_level_scope_filters_the_list(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $mine = $this->makeAccommodation($franchise, $zone);
        $other = $this->makeHotelReservationScenario()['accommodation'];

        $actor = $this->makeUserWithPermission('accommodations.manage', 'franchise', $franchise->id);

        $component = Livewire::actingAs($actor)->test(AccommodationsManage::class);
        $accommodations = $component->viewData('accommodations');

        $this->assertTrue($accommodations->contains('id', $mine->id));
        $this->assertFalse($accommodations->contains('id', $other->id));
    }

    public function test_a_franchise_scoped_manager_cannot_open_another_franchises_accommodation_room_view(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $mine = $this->makeAccommodation($franchise, $zone);
        $other = $this->makeHotelReservationScenario()['accommodation'];

        $actor = $this->makeUserWithPermission('accommodations.manage', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(AccommodationsManage::class)
            ->call('viewAccommodation', $other->id)
            ->assertStatus(404);
    }

    // ============================== HotelReservations\Manage ==============================

    public function test_reservations_denied_without_permission(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(HotelReservationsManage::class)->assertForbidden();
    }

    public function test_reservations_allowed_with_permission(): void
    {
        $actor = $this->makeUserWithPermission('hotel_reservations.view', 'global');

        Livewire::actingAs($actor)->test(HotelReservationsManage::class)->assertOk();
    }

    public function test_a_franchise_scoped_viewer_cannot_open_another_franchises_reservation(): void
    {
        $mine = $this->makeHotelReservationScenario('pending');
        $other = $this->makeHotelReservationScenario('pending');

        $actor = $this->makeUserWithPermission('hotel_reservations.view', 'franchise', $mine['franchise']->id);

        Livewire::actingAs($actor)->test(HotelReservationsManage::class)
            ->call('viewReservation', $other['reservation']->id)
            ->assertStatus(404);
    }

    public function test_row_level_scope_filters_the_reservations_list(): void
    {
        $mine = $this->makeHotelReservationScenario('pending');
        $other = $this->makeHotelReservationScenario('pending');

        $actor = $this->makeUserWithPermission('hotel_reservations.view', 'franchise', $mine['franchise']->id);

        $component = Livewire::actingAs($actor)->test(HotelReservationsManage::class);
        $reservations = $component->viewData('reservations');

        $this->assertTrue($reservations->contains('id', $mine['reservation']->id));
        $this->assertFalse($reservations->contains('id', $other['reservation']->id));
    }

    public function test_cancel_action_requires_hotel_reservations_cancel_permission(): void
    {
        $actor = $this->makeUserWithPermission('hotel_reservations.view', 'global');
        $scenario = $this->makeHotelReservationScenario('pending');
        $this->actingAs($actor);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $manage = new HotelReservationsManage();
        $manage->selectedReservationId = $scenario['reservation']->id;
        $manage->cancelReservation(app(\App\Actions\AdminCancelHotelReservationAction::class));
    }
}
