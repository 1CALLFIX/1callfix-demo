<?php

namespace Tests\Feature\Parcel;

use App\Livewire\ParcelOrders\Manage as ParcelOrdersManage;
use Livewire\Livewire;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
