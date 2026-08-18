<?php

namespace Tests\Feature\Marketplace;

use App\Livewire\AddOns\Manage as AddOnsManage;
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

    /**
     * Admin Command Center mission (Security audit) — createStore() had no
     * scope check at all: the zones dropdown is unscoped, so a franchise-
     * scoped actor could create a store under a completely different
     * franchise just by picking its zone. edit/saveEdit were already safe.
     */
    public function test_stores_create_denied_for_a_different_franchises_zone(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $otherFranchiseOrder = $this->makeMarketplaceOrderScenario();
        $owner = $this->makeStoreOwner($franchise, $zone);

        $actor = $this->makeUserWithPermission('stores.manage', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(StoresManage::class)
            ->set('providerId', $owner->id)
            ->set('zoneId', $otherFranchiseOrder['zone']->id)
            ->set('module', 'commerce')
            ->set('name', 'Cross-Scope Attempt')
            ->set('addressLine', '1 Nowhere St')
            ->set('lat', 1.0)
            ->set('lng', 1.0)
            ->call('createStore')
            ->assertHasErrors('zoneId');

        $this->assertDatabaseMissing('stores', ['zone_id' => $otherFranchiseOrder['zone']->id, 'name' => 'Cross-Scope Attempt']);
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

    /**
     * Admin Command Center mission (Security audit) — createProduct() had no
     * scope check at all: the stores dropdown is unscoped, so a franchise-
     * scoped actor could add a product to a store owned by a completely
     * different franchise just by picking it. edit/saveEdit/addVariant were
     * already safe (scopedProductsQuery() 404s out-of-scope).
     */
    public function test_products_create_denied_for_a_different_franchises_store(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $otherStore = $this->makeMarketplaceOrderScenario()['store'];

        $actor = $this->makeUserWithPermission('products.manage', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(ProductsManage::class)
            ->set('storeId', $otherStore->id)
            ->set('name', 'Cross-Scope Attempt')
            ->set('price', '50')
            ->set('stock', '5')
            ->call('createProduct')
            ->assertHasErrors('storeId');

        $this->assertDatabaseMissing('products', ['store_id' => $otherStore->id, 'name' => 'Cross-Scope Attempt']);
    }

    // ============================== AddOns\Manage ==============================

    public function test_add_ons_denied_without_permission(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(AddOnsManage::class)->assertForbidden();
    }

    public function test_add_ons_allowed_with_products_manage_permission(): void
    {
        $actor = $this->makeUserWithPermission('products.manage', 'global');

        Livewire::actingAs($actor)->test(AddOnsManage::class)->assertOk();
    }

    /** Same bug class as Products' create -- see that test's docblock; AddOns reuses products.manage. */
    public function test_add_ons_create_denied_for_a_different_franchises_store(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $otherStore = $this->makeMarketplaceOrderScenario()['store'];

        $actor = $this->makeUserWithPermission('products.manage', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(AddOnsManage::class)
            ->set('storeId', $otherStore->id)
            ->set('name', 'Cross-Scope Attempt')
            ->set('price', '10')
            ->call('createAddOn')
            ->assertHasErrors('storeId');

        $this->assertDatabaseMissing('add_ons', ['store_id' => $otherStore->id, 'name' => 'Cross-Scope Attempt']);
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
