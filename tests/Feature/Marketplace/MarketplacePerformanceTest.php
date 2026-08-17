<?php

namespace Tests\Feature\Marketplace;

use App\Livewire\MarketplaceOrders\Manage as MarketplaceOrdersManage;
use App\Livewire\Products\Manage as ProductsManage;
use App\Livewire\Stores\Manage as StoresManage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/** Phase 24 (Marketplace Foundation). N+1 guards for the three admin list screens, mirroring PropertyRentalPerformanceTest's exact-equality methodology. */
class MarketplacePerformanceTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;

    private function countQueriesFor(\Closure $callback): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $callback();

        $count = count(DB::getQueryLog());
        DB::flushQueryLog();

        return $count;
    }

    public function test_stores_list_does_not_n_plus_one(): void
    {
        $actor = $this->makeSuperAdmin();
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->makeStore($franchise, $zone);
        $this->makeStore($franchise, $zone);

        $few = $this->countQueriesFor(fn () => Livewire::actingAs($actor)->test(StoresManage::class));

        $this->makeStore($franchise, $zone);
        $this->makeStore($franchise, $zone);
        $this->makeStore($franchise, $zone);

        $many = $this->countQueriesFor(fn () => Livewire::actingAs($actor)->test(StoresManage::class));

        $this->assertSame($few, $many, 'Query count must not grow with the number of stores rendered.');
    }

    public function test_products_list_does_not_n_plus_one(): void
    {
        $actor = $this->makeSuperAdmin();
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $store = $this->makeStore($franchise, $zone);
        $this->makeProduct($store);
        $this->makeProduct($store);

        $few = $this->countQueriesFor(fn () => Livewire::actingAs($actor)->test(ProductsManage::class));

        $this->makeProduct($store);
        $this->makeProduct($store);
        $this->makeProduct($store);

        $many = $this->countQueriesFor(fn () => Livewire::actingAs($actor)->test(ProductsManage::class));

        $this->assertSame($few, $many, 'Query count must not grow with the number of products rendered.');
    }

    public function test_orders_list_does_not_n_plus_one(): void
    {
        $actor = $this->makeSuperAdmin();
        $this->makeMarketplaceOrderScenario('pending');
        $this->makeMarketplaceOrderScenario('pending');

        $few = $this->countQueriesFor(fn () => Livewire::actingAs($actor)->test(MarketplaceOrdersManage::class));

        $this->makeMarketplaceOrderScenario('accepted');
        $this->makeMarketplaceOrderScenario('preparing');
        $this->makeMarketplaceOrderScenario('completed');

        $many = $this->countQueriesFor(fn () => Livewire::actingAs($actor)->test(MarketplaceOrdersManage::class));

        $this->assertSame($few, $many, 'Query count must not grow with the number of orders rendered.');
    }
}
