<?php

namespace Tests\Feature\Taxi;

use App\Livewire\TaxiRides\Manage as TaxiRidesManage;
use Livewire\Livewire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\TaxiRideFixtureHelpers;
use Tests\TestCase;

/** Phase 22.6 (Taxi) admin screen — permission gate + row-level scope, exact mirror of ParcelOrdersAuthorizationTest. */
class TaxiRidesAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use TaxiRideFixtureHelpers;

    public function test_denied_without_taxi_rides_view_permission(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(TaxiRidesManage::class)->assertForbidden();
    }

    public function test_allowed_with_taxi_rides_view_permission(): void
    {
        $actor = $this->makeUserWithPermission('taxi_rides.view', 'global');

        Livewire::actingAs($actor)->test(TaxiRidesManage::class)->assertOk();
    }

    public function test_super_admin_bypasses_the_gate(): void
    {
        $actor = $this->makeSuperAdmin();

        Livewire::actingAs($actor)->test(TaxiRidesManage::class)->assertOk();
    }

    public function test_a_franchise_scoped_viewer_cannot_open_another_franchises_ride(): void
    {
        $mine = $this->makeTaxiRideScenario('requested');
        $other = $this->makeTaxiRideScenario('requested');

        $actor = $this->makeUserWithPermission('taxi_rides.view', 'franchise', $mine['franchise']->id);

        Livewire::actingAs($actor)->test(TaxiRidesManage::class)
            ->call('viewRide', $other['ride']->id)
            ->assertStatus(404);
    }

    public function test_row_level_scope_filters_the_list_query_itself(): void
    {
        $mine = $this->makeTaxiRideScenario('requested');
        $other = $this->makeTaxiRideScenario('requested');

        $actor = $this->makeUserWithPermission('taxi_rides.view', 'franchise', $mine['franchise']->id);

        $component = Livewire::actingAs($actor)->test(TaxiRidesManage::class);
        $rides = $component->viewData('rides');

        $this->assertTrue($rides->contains('id', $mine['ride']->id));
        $this->assertFalse($rides->contains('id', $other['ride']->id));
    }

    public function test_cancel_action_requires_taxi_rides_cancel_permission(): void
    {
        $actor = $this->makeUserWithPermission('taxi_rides.view', 'global');
        $scenario = $this->makeTaxiRideScenario('requested');
        $this->actingAs($actor);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $manage = new TaxiRidesManage();
        $manage->selectedRideId = $scenario['ride']->id;
        $manage->cancelRide(app(\App\Actions\AdminCancelTaxiRideAction::class));
    }
}
