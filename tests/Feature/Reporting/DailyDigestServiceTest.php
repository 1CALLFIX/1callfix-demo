<?php

namespace Tests\Feature\Reporting;

use App\Models\Booking;
use App\Services\Reporting\DailyDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Sidebar Reorganization + Daily Digest session, Part 2 — "Scope-aware:
 * Super Admin gets a platform-wide version; each Franchise-scoped admin
 * gets their own franchise's data only — reuse the exact same
 * AuthorizationService::scopeQuery() pattern every other screen in this
 * app already uses." Every assertion below is content-based (real counts
 * from real bookings), not "a query ran without throwing".
 */
class DailyDigestServiceTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    public function test_super_admin_kpis_cover_every_franchise(): void
    {
        $scenarioA = $this->makeBookingScenario('completed');
        $scenarioA['booking']->forceFill(['completed_at' => now(), 'price_final' => 500])->save();
        $scenarioB = $this->makeBookingScenario('completed');
        $scenarioB['booking']->forceFill(['completed_at' => now(), 'price_final' => 300])->save();

        $superAdmin = $this->makeSuperAdmin();

        $digest = app(DailyDigestService::class)->forUser($superAdmin);

        $this->assertSame(2, $digest['kpis']['bookings_today']);
        $this->assertSame(2, $digest['kpis']['completed_today']);
        $this->assertEqualsWithDelta(800.0, $digest['kpis']['revenue_today'], 0.001);
    }

    public function test_franchise_scoped_admin_sees_only_their_own_franchise(): void
    {
        $scenarioA = $this->makeBookingScenario('completed');
        $scenarioA['booking']->forceFill(['completed_at' => now(), 'price_final' => 500])->save();
        $scenarioB = $this->makeBookingScenario('completed'); // a DIFFERENT franchise entirely
        $scenarioB['booking']->forceFill(['completed_at' => now(), 'price_final' => 300])->save();

        $franchiseAdmin = $this->makeUserWithPermission('dashboard.view', 'franchise', $scenarioA['franchise']->id);

        $digest = app(DailyDigestService::class)->forUser($franchiseAdmin);

        $this->assertSame(1, $digest['kpis']['bookings_today'], 'Must only count the admin\'s own franchise, not the other one.');
        $this->assertEqualsWithDelta(500.0, $digest['kpis']['revenue_today'], 0.001);
    }

    public function test_bookings_yesterday_is_scoped_and_date_bounded_separately_from_today(): void
    {
        $scenario = $this->makeBookingScenario('completed');
        $scenario['booking']->forceFill(['created_at' => now()->subDay()->setTime(10, 0)])->save();

        $superAdmin = $this->makeSuperAdmin();
        $digest = app(DailyDigestService::class)->forUser($superAdmin);

        $this->assertSame(0, $digest['kpis']['bookings_today']);
        $this->assertSame(1, $digest['kpis']['bookings_yesterday']);
    }

    public function test_insights_are_included_only_when_recipient_holds_operations_view(): void
    {
        $scenario = $this->makeBookingScenario('searching_provider');
        $scenario['booking']->forceFill(['created_at' => now()->subHours(2)])->save(); // past stuck threshold

        $withOps = $this->makeUserWithPermission('dashboard.view', 'global');
        $this->grantPermission($withOps, 'operations.view', 'global');
        $withoutOps = $this->makeUserWithPermission('dashboard.view', 'global');

        $digestWith = app(DailyDigestService::class)->forUser($withOps);
        $digestWithout = app(DailyDigestService::class)->forUser($withoutOps);

        $this->assertNotNull($digestWith['insights']);
        $this->assertGreaterThan(0, $digestWith['insights']['stuck_bookings']->count());
        $this->assertNull($digestWithout['insights'], 'A recipient without operations.view must get a KPI-only digest, not an empty/broken insights section.');
    }

    public function test_completion_rate_is_of_concluded_bookings_not_all_created_today(): void
    {
        $scenario = $this->makeBookingScenario('completed');
        $scenario['booking']->forceFill(['completed_at' => now(), 'price_final' => 500])->save();

        // A second, still in-progress booking today -- must NOT count against completion rate.
        Booking::create([
            'code' => 'TST-INPROG-'.random_int(1, 99999),
            'franchise_id' => $scenario['franchise']->id, 'zone_id' => $scenario['zone']->id,
            'customer_id' => $scenario['customer']->id, 'service_id' => $scenario['service']->id, 'address_id' => $scenario['address']->id,
            'status' => 'in_progress', 'price_quoted' => 400, 'payment_status' => 'pending', 'payment_method' => 'online',
        ]);

        $superAdmin = $this->makeSuperAdmin();
        $digest = app(DailyDigestService::class)->forUser($superAdmin);

        $this->assertSame(100.0, $digest['kpis']['completion_rate']);
    }
}
