<?php

namespace Tests\Feature\Parcel;

use App\Livewire\ParcelOrders\Manage as ParcelOrdersManage;
use Livewire\Livewire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Phase 22.4 (Parcel) admin screen — permission gate + row-level scope,
 * same conventions this codebase's own RBAC audits (Phase 11/19) already
 * verified for every other admin screen.
 */
class ParcelOrdersAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;

    public function test_denied_without_parcel_orders_view_permission(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(ParcelOrdersManage::class)->assertForbidden();
    }

    public function test_allowed_with_parcel_orders_view_permission(): void
    {
        $actor = $this->makeUserWithPermission('parcel_orders.view', 'global');

        Livewire::actingAs($actor)->test(ParcelOrdersManage::class)->assertOk();
    }

    public function test_super_admin_bypasses_the_gate(): void
    {
        $actor = $this->makeSuperAdmin();

        Livewire::actingAs($actor)->test(ParcelOrdersManage::class)->assertOk();
    }

    public function test_a_franchise_scoped_viewer_cannot_open_another_franchises_order(): void
    {
        $mine = $this->makeParcelOrderScenario('pending');
        $other = $this->makeParcelOrderScenario('pending');

        $actor = $this->makeUserWithPermission('parcel_orders.view', 'franchise', $mine['franchise']->id);

        Livewire::actingAs($actor)->test(ParcelOrdersManage::class)
            ->call('viewOrder', $other['order']->id)
            ->assertStatus(404);
    }

    public function test_a_franchise_scoped_viewer_can_open_their_own_franchises_order(): void
    {
        $mine = $this->makeParcelOrderScenario('pending');
        $actor = $this->makeUserWithPermission('parcel_orders.view', 'franchise', $mine['franchise']->id);

        Livewire::actingAs($actor)->test(ParcelOrdersManage::class)
            ->call('viewOrder', $mine['order']->id)
            ->assertSet('selectedOrderId', $mine['order']->id);
    }

    public function test_row_level_scope_filters_the_list_query_itself(): void
    {
        $mine = $this->makeParcelOrderScenario('pending');
        $other = $this->makeParcelOrderScenario('pending');

        $actor = $this->makeUserWithPermission('parcel_orders.view', 'franchise', $mine['franchise']->id);

        $component = Livewire::actingAs($actor)->test(ParcelOrdersManage::class);
        $orders = $component->viewData('orders');

        $this->assertTrue($orders->contains('id', $mine['order']->id));
        $this->assertFalse($orders->contains('id', $other['order']->id));
    }

    /**
     * Admin Command Center mission (Security audit) — createOrder() had no
     * scope check at all: the zones dropdown is unscoped, so a zone-scoped
     * call-center actor could create a parcel order under a completely
     * different franchise just by picking its zone. Same bug class as the
     * catalog Manage screens' create actions, same fix pattern Bookings\
     * Index::createBooking() already established.
     */
    public function test_create_order_denied_for_a_different_franchises_zone(): void
    {
        // Queue::fake() -- createOrder() dispatches a real ParcelDispatchJob
        // on success; under the test suite's QUEUE_CONNECTION=sync driver
        // that job runs synchronously and, with zero eligible riders in
        // this scenario, self-requeues with a REAL elapsed delay between
        // rounds (offer_timeout_seconds default 25s x up to 6 rounds) --
        // faking the queue isolates this test from that real side effect.
        Queue::fake();

        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $other = $this->makeParcelOrderScenario('pending'); // separate franchise/zone
        $customer = $this->makeCustomer();

        $actor = $this->makeUserWithPermission('parcel_orders.view', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(ParcelOrdersManage::class)
            ->set('selectedCustomerId', $customer->id)
            ->set('selectedZoneId', $other['zone']->id)
            ->set('pickupAddressLine', 'Pickup')
            ->set('pickupLat', 1.0)
            ->set('pickupLng', 1.0)
            ->set('dropoffAddressLine', 'Dropoff')
            ->set('dropoffLat', 1.01)
            ->set('dropoffLng', 1.01)
            ->set('paymentMethod', 'cash')
            ->call('createOrder', app(\App\Actions\CreateParcelOrderAction::class))
            ->assertHasErrors('selectedZoneId');

        $this->assertDatabaseMissing('parcel_orders', ['zone_id' => $other['zone']->id, 'customer_id' => $customer->id]);
    }

    public function test_create_order_allowed_within_own_franchise_scope(): void
    {
        Queue::fake(); // see test_create_order_denied_for_a_different_franchises_zone's own docblock

        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();

        $actor = $this->makeUserWithPermission('parcel_orders.view', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(ParcelOrdersManage::class)
            ->set('selectedCustomerId', $customer->id)
            ->set('selectedZoneId', $zone->id)
            ->set('pickupAddressLine', 'Pickup')
            ->set('pickupLat', 1.0)
            ->set('pickupLng', 1.0)
            ->set('dropoffAddressLine', 'Dropoff')
            ->set('dropoffLat', 1.01)
            ->set('dropoffLng', 1.01)
            ->set('paymentMethod', 'cash')
            ->call('createOrder', app(\App\Actions\CreateParcelOrderAction::class))
            ->assertHasNoErrors();

        $this->assertDatabaseHas('parcel_orders', ['zone_id' => $zone->id, 'customer_id' => $customer->id]);
    }

    public function test_cancel_action_requires_parcel_orders_cancel_permission(): void
    {
        // Holds .view but not .cancel -- a real, narrower permission boundary.
        $actor = $this->makeUserWithPermission('parcel_orders.view', 'global');
        $scenario = $this->makeParcelOrderScenario('pending');
        $this->actingAs($actor);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $manage = new ParcelOrdersManage();
        $manage->selectedOrderId = $scenario['order']->id;
        $manage->cancelOrder(app(\App\Actions\AdminCancelParcelOrderAction::class));
    }
}
