<?php

namespace Tests\Feature\Marketplace;

use App\Livewire\MarketplaceOrders\Manage as MarketplaceOrdersManage;
use App\Livewire\Products\Manage as ProductsManage;
use App\Livewire\Stores\Manage as StoresManage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/** Phase 24 (Marketplace Foundation) admin screens — permission gate + row-level scope, mirroring PropertyRentalAuthorizationTest. */
class MarketplaceAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;

    // ============================== Stores\Manage ==============================

    public function test_stores_denied_without_permission(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(StoresManage::class)->assertForbidden();
    }

    public function test_stores_allowed_with_permission(): void
    {
        $actor = $this->makeUserWithPermission('stores.manage', 'global');

        Livewire::actingAs($actor)->test(StoresManage::class)->assertOk();
    }

    public function test_stores_row_level_scope_filters_the_list(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $mine = $this->makeStore($franchise, $zone);
        $other = $this->makeMarketplaceOrderScenario()['store'];

        $actor = $this->makeUserWithPermission('stores.manage', 'franchise', $franchise->id);

        $component = Livewire::actingAs($actor)->test(StoresManage::class);
        $stores = $component->viewData('stores');

        $this->assertTrue($stores->contains('id', $mine->id));
        $this->assertFalse($stores->contains('id', $other->id));
    }

    // ============================== Products\Manage ==============================

    public function test_products_denied_without_permission(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(ProductsManage::class)->assertForbidden();
    }

    public function test_products_row_level_scope_filters_through_the_owning_store(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $store = $this->makeStore($franchise, $zone);
        $mine = $this->makeProduct($store);
        $otherScenario = $this->makeMarketplaceOrderScenario();
        $other = $otherScenario['product'];

        $actor = $this->makeUserWithPermission('products.manage', 'franchise', $franchise->id);

        $component = Livewire::actingAs($actor)->test(ProductsManage::class);
        $products = $component->viewData('products');

        $this->assertTrue($products->contains('id', $mine->id));
        $this->assertFalse($products->contains('id', $other->id));
    }

    // ============================== MarketplaceOrders\Manage ==============================

    public function test_orders_denied_without_permission(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(MarketplaceOrdersManage::class)->assertForbidden();
    }

    public function test_orders_allowed_with_permission(): void
    {
        $actor = $this->makeUserWithPermission('marketplace_orders.view', 'global');

        Livewire::actingAs($actor)->test(MarketplaceOrdersManage::class)->assertOk();
    }

    public function test_a_franchise_scoped_viewer_cannot_open_another_franchises_order(): void
    {
        $mine = $this->makeMarketplaceOrderScenario('pending');
        $other = $this->makeMarketplaceOrderScenario('pending');

        $actor = $this->makeUserWithPermission('marketplace_orders.view', 'franchise', $mine['franchise']->id);

        Livewire::actingAs($actor)->test(MarketplaceOrdersManage::class)
            ->call('viewOrder', $other['order']->id)
            ->assertStatus(404);
    }

    public function test_row_level_scope_filters_the_orders_list(): void
    {
        $mine = $this->makeMarketplaceOrderScenario('pending');
        $other = $this->makeMarketplaceOrderScenario('pending');

        $actor = $this->makeUserWithPermission('marketplace_orders.view', 'franchise', $mine['franchise']->id);

        $component = Livewire::actingAs($actor)->test(MarketplaceOrdersManage::class);
        $orders = $component->viewData('orders');

        $this->assertTrue($orders->contains('id', $mine['order']->id));
        $this->assertFalse($orders->contains('id', $other['order']->id));
    }

    public function test_cancel_action_requires_marketplace_orders_cancel_permission(): void
    {
        $actor = $this->makeUserWithPermission('marketplace_orders.view', 'global');
        $scenario = $this->makeMarketplaceOrderScenario('pending');
        $this->actingAs($actor);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $manage = new MarketplaceOrdersManage();
        $manage->selectedOrderId = $scenario['order']->id;
        $manage->cancelOrder(app(\App\Actions\AdminCancelMarketplaceOrderAction::class));
    }
}
