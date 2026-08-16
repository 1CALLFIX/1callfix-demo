<?php

namespace Tests\Feature\PropertyRental;

use App\Livewire\Properties\Manage as PropertiesManage;
use App\Livewire\PropertyReservations\Manage as PropertyReservationsManage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\PropertyRentalFixtureHelpers;
use Tests\TestCase;

/** Phase 22.7 (Property Rental). N+1 guards for both admin list screens, mirroring ParcelOrdersPerformanceTest's exact-equality methodology. */
class PropertyRentalPerformanceTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use PropertyRentalFixtureHelpers;

    private function countQueriesFor(\Closure $callback): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $callback();

        $count = count(DB::getQueryLog());
        DB::flushQueryLog();

        return $count;
    }

    public function test_properties_list_does_not_n_plus_one(): void
    {
        $actor = $this->makeSuperAdmin();
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->makeProperty($franchise, $zone);
        $this->makeProperty($franchise, $zone);

        $few = $this->countQueriesFor(fn () => Livewire::actingAs($actor)->test(PropertiesManage::class));

        $this->makeProperty($franchise, $zone);
        $this->makeProperty($franchise, $zone);
        $this->makeProperty($franchise, $zone);

        $many = $this->countQueriesFor(fn () => Livewire::actingAs($actor)->test(PropertiesManage::class));

        $this->assertSame($few, $many, 'Query count must not grow with the number of properties rendered.');
    }

    public function test_reservations_list_does_not_n_plus_one(): void
    {
        $actor = $this->makeSuperAdmin();
        $this->makePropertyReservationScenario('pending');
        $this->makePropertyReservationScenario('pending');

        $few = $this->countQueriesFor(fn () => Livewire::actingAs($actor)->test(PropertyReservationsManage::class));

        $this->makePropertyReservationScenario('confirmed');
        $this->makePropertyReservationScenario('checked_in');
        $this->makePropertyReservationScenario('completed');

        $many = $this->countQueriesFor(fn () => Livewire::actingAs($actor)->test(PropertyReservationsManage::class));

        $this->assertSame($few, $many, 'Query count must not grow with the number of reservations rendered.');
    }
}
