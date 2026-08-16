<?php

namespace Tests\Feature\Parcel;

use App\Livewire\ParcelOrders\Manage as ParcelOrdersManage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Phase 22.4 (Parcel). N+1 guard for the admin list screen — same
 * discipline this mission applied elsewhere (Phase 18's own N+1 sweep) —
 * proving the query count doesn't grow linearly with the number of orders
 * rendered, since `scopedOrdersQuery()` eager-loads its relations.
 */
class ParcelOrdersPerformanceTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;

    public function test_the_list_screen_does_not_n_plus_one_across_orders(): void
    {
        $actor = $this->makeSuperAdmin();
        $this->makeParcelOrderScenario('pending');
        $this->makeParcelOrderScenario('pending');
        $this->makeParcelOrderScenario('assigned');

        $queryCountFew = $this->countQueriesFor(fn () => Livewire::actingAs($actor)->test(ParcelOrdersManage::class));

        // Now with more rows -- if eager loading is working, the query
        // COUNT stays flat; only ROW counts within each query grow.
        $this->makeParcelOrderScenario('pending');
        $this->makeParcelOrderScenario('pending');
        $this->makeParcelOrderScenario('picked_up');
        $this->makeParcelOrderScenario('delivered');

        $queryCountMany = $this->countQueriesFor(fn () => Livewire::actingAs($actor)->test(ParcelOrdersManage::class));

        $this->assertSame(
            $queryCountFew,
            $queryCountMany,
            'Query count must not grow at all with the number of parcel orders rendered -- both renders paginate to the same page size.'
        );
    }

    /** A single, non-accumulating query-count measurement -- DB::listen() itself has no "un-listen", so each call uses Illuminate's own query log instead, flushed before and after. */
    private function countQueriesFor(\Closure $callback): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $callback();

        $count = count(DB::getQueryLog());
        DB::flushQueryLog();

        return $count;
    }
}
