<?php

namespace Tests\Feature\Operations;

use App\Models\FranchiseModule;
use App\Models\Provider;
use App\Models\Setting;
use App\Models\User;
use App\Services\Operations\ZoneCoverageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Admin Polish + AI session, Part 2 item 1 — "zones with unusually low
 * provider coverage right now". Real regression coverage for the
 * detection logic (the flat, Setting-driven minimum threshold).
 */
class ZoneCoverageServiceTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    /** FranchiseObserver already auto-creates a FranchiseModule row (all-false defaults) the instant Franchise::create() runs inside makeFranchiseTree() -- updateOrCreate() here, not create(), or this collides with that row's own unique franchise_id constraint. */
    private function activateServiceModule($franchise): void
    {
        FranchiseModule::updateOrCreate(['franchise_id' => $franchise->id], ['service' => true]);
    }

    private function makeOnlineApprovedProvider($franchise, $zone): Provider
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Online Provider', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id,
        ]);

        return Provider::create([
            'user_id' => $user->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true, 'is_online' => true,
        ]);
    }

    public function test_flags_a_zone_with_zero_online_providers(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateServiceModule($franchise);
        $admin = $this->makeSuperAdmin();

        $result = app(ZoneCoverageService::class)->detect($admin);

        $flagged = $result->firstWhere('zone.id', $zone->id);
        $this->assertNotNull($flagged, 'Expected the zone with zero online providers to be flagged.');
        $this->assertSame(0, $flagged['online_providers']);
    }

    public function test_does_not_flag_a_zone_meeting_the_default_minimum(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateServiceModule($franchise);
        $this->makeOnlineApprovedProvider($franchise, $zone);
        $admin = $this->makeSuperAdmin();

        $result = app(ZoneCoverageService::class)->detect($admin);

        $this->assertNull($result->firstWhere('zone.id', $zone->id));
    }

    public function test_offline_and_unapproved_providers_do_not_count_toward_coverage(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateServiceModule($franchise);

        $offline = $this->makeOnlineApprovedProvider($franchise, $zone);
        $offline->update(['is_online' => false]);

        $unapproved = $this->makeOnlineApprovedProvider($franchise, $zone);
        $unapproved->update(['kyc_status' => 'pending']);

        $admin = $this->makeSuperAdmin();
        $flagged = app(ZoneCoverageService::class)->detect($admin)->firstWhere('zone.id', $zone->id);

        $this->assertNotNull($flagged, 'Neither offline nor un-approved providers should count toward live coverage.');
        $this->assertSame(0, $flagged['online_providers']);
    }

    public function test_threshold_is_setting_driven(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateServiceModule($franchise);
        $this->makeOnlineApprovedProvider($franchise, $zone);
        $admin = $this->makeSuperAdmin();

        // 1 online provider clears the default minimum (1)...
        $this->assertNull(app(ZoneCoverageService::class)->detect($admin)->firstWhere('zone.id', $zone->id));

        // ...but not once an admin raises the configured minimum.
        Setting::set('operations.coverage.min_online_providers', '2');
        $flagged = app(ZoneCoverageService::class)->detect($admin)->firstWhere('zone.id', $zone->id);
        $this->assertNotNull($flagged);
        $this->assertSame(2, $flagged['threshold']);
    }

    public function test_inactive_zone_is_never_flagged(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateServiceModule($franchise);
        $zone->update(['is_active' => false]);
        $admin = $this->makeSuperAdmin();

        $this->assertNull(app(ZoneCoverageService::class)->detect($admin)->firstWhere('zone.id', $zone->id));
    }

    public function test_a_franchise_scoped_actor_never_sees_another_franchises_zone_coverage_gap(): void
    {
        [, , $franchiseOutside, $zoneOutside] = $this->makeFranchiseTree();
        $this->activateServiceModule($franchiseOutside); // zero online providers -- would be flagged for anyone who could see it

        [, , $franchiseInside, ] = $this->makeFranchiseTree();
        $actor = $this->makeUserWithPermission('operations.view', 'franchise', $franchiseInside->id);

        $result = app(ZoneCoverageService::class)->detect($actor);

        $this->assertNull($result->firstWhere('zone.id', $zoneOutside->id), 'A franchise-scoped actor must never see another franchise\'s zone in the coverage-gap list.');
    }
}
