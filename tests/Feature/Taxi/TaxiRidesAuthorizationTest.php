<?php

namespace Tests\Feature\Taxi;

use App\Livewire\TaxiRides\Manage as TaxiRidesManage;
use Livewire\Livewire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    /** Same bug class as ParcelOrdersAuthorizationTest's createOrder coverage -- see that test's docblock. */
    public function test_create_ride_denied_for_a_different_franchises_zone(): void
    {
        // Queue::fake() -- see ParcelOrdersAuthorizationTest's identical
        // note; createRide() dispatches a real TaxiDispatchJob on success,
        // which self-requeues with a real elapsed delay when no eligible
        // drivers exist, under this suite's QUEUE_CONNECTION=sync driver.
        Queue::fake();

        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateTaxiFor($franchise);
        $other = $this->makeTaxiRideScenario('requested');
        $customer = $this->makeCustomer();

        $actor = $this->makeUserWithPermission('taxi_rides.view', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(TaxiRidesManage::class)
            ->set('selectedCustomerId', $customer->id)
            ->set('selectedZoneId', $other['zone']->id)
            ->set('pickupAddressLine', 'Pickup')
            ->set('pickupLat', 1.0)
            ->set('pickupLng', 1.0)
            ->set('paymentMethod', 'cash')
            ->call('createRide', app(\App\Actions\CreateTaxiRideAction::class))
            ->assertHasErrors('selectedZoneId');

        $this->assertDatabaseMissing('taxi_rides', ['zone_id' => $other['zone']->id, 'customer_id' => $customer->id]);
    }

    public function test_create_ride_allowed_within_own_franchise_scope(): void
    {
        Queue::fake(); // see test_create_ride_denied_for_a_different_franchises_zone's own docblock

        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateTaxiFor($franchise);
        $customer = $this->makeCustomer();

        $actor = $this->makeUserWithPermission('taxi_rides.view', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(TaxiRidesManage::class)
            ->set('selectedCustomerId', $customer->id)
            ->set('selectedZoneId', $zone->id)
            ->set('pickupAddressLine', 'Pickup')
            ->set('pickupLat', 1.0)
            ->set('pickupLng', 1.0)
            ->set('paymentMethod', 'cash')
            ->call('createRide', app(\App\Actions\CreateTaxiRideAction::class))
            ->assertHasNoErrors();

        $this->assertDatabaseHas('taxi_rides', ['zone_id' => $zone->id, 'customer_id' => $customer->id]);
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
