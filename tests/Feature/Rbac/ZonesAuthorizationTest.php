<?php

namespace Tests\Feature\Rbac;

use App\Livewire\Zones\Manage;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * zones.manage was seeded but never checked anywhere (Slice 2 finding #1).
 * Scope is franchise/city/country, mirroring Bookings\Show::bookingScope().
 */
class ZonesAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    /**
     * Since Phase 11 added a mount()-level zones.manage gate to this whole
     * screen (it never had a separate .view permission), an actor with
     * zero permissions is now denied at mount() — never reaches save() to
     * trigger its own action-level check.
     */
    public function test_user_without_permission_cannot_view_or_create_zone(): void
    {
        $franchise = $this->makeFranchise();
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(Manage::class)->assertForbidden();

        $this->assertDatabaseMissing('zones', ['name' => 'Blocked Zone']);
    }

    /**
     * Platform-structure policy pass (follow-up to item 51, 2026-08-20):
     * zones.manage is now restricted to global/country/city grant scope
     * only (see AuthorizationService::RESTRICTED_GRANT_SCOPES's own
     * docblock) — country_admin/city_admin's own seeded design is
     * unaffected, but a FRANCHISE-scoped grant of zones.manage (which no
     * seeded system role carries by default, but this test's own
     * makeUserWithPermission() fixture can still construct one directly)
     * can no longer create/edit a zone at all. This REPLACES the prior
     * version of this test (which asserted the opposite): that was correct
     * before this policy pass, not after it.
     */
    public function test_franchise_scoped_grant_can_no_longer_create_a_zone(): void
    {
        $franchise = $this->makeFranchise();
        $actor = $this->makeUserWithPermission('zones.manage', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('franchiseId', (string) $franchise->id)
            ->set('name', 'Blocked Zone')
            ->set('boundaryPolygonJson', json_encode([['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2], ['lat' => 3, 'lng' => 3]]))
            ->call('save')
            ->assertHasErrors(['permission']);

        $this->assertDatabaseMissing('zones', ['name' => 'Blocked Zone']);
    }

    public function test_country_scoped_grant_can_still_create_a_zone(): void
    {
        $city = $this->makeCity();
        $franchise = $this->makeFranchise($city);
        $actor = $this->makeUserWithPermission('zones.manage', 'country', $city->country_id);

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('franchiseId', (string) $franchise->id)
            ->set('name', 'Allowed Zone')
            ->set('boundaryPolygonJson', json_encode([['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2], ['lat' => 3, 'lng' => 3]]))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('zones', ['name' => 'Allowed Zone', 'franchise_id' => $franchise->id]);
    }

    public function test_user_scoped_to_a_different_franchise_is_denied(): void
    {
        $ownFranchise = $this->makeFranchise();
        $otherFranchise = $this->makeFranchise();
        $actor = $this->makeUserWithPermission('zones.manage', 'franchise', $ownFranchise->id);

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('franchiseId', (string) $otherFranchise->id)
            ->set('name', 'Cross Franchise Zone')
            ->set('boundaryPolygonJson', json_encode([['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2], ['lat' => 3, 'lng' => 3]]))
            ->call('save')
            ->assertHasErrors(['permission']);

        $this->assertDatabaseMissing('zones', ['name' => 'Cross Franchise Zone']);
    }

    public function test_city_scoped_grant_covers_a_zone_in_a_franchise_within_that_city(): void
    {
        $country = $this->makeCountry();
        $city = $this->makeCity($country);
        $franchise = $this->makeFranchise($city);
        $actor = $this->makeUserWithPermission('zones.manage', 'city', $city->id);

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('franchiseId', (string) $franchise->id)
            ->set('name', 'City Scoped Zone')
            ->set('boundaryPolygonJson', json_encode([['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2], ['lat' => 3, 'lng' => 3]]))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('zones', ['name' => 'City Scoped Zone']);
    }

    public function test_city_scoped_grant_does_not_cover_a_different_city(): void
    {
        $franchiseInOtherCity = $this->makeFranchise();
        $unrelatedCity = $this->makeCity();
        $actor = $this->makeUserWithPermission('zones.manage', 'city', $unrelatedCity->id);

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('franchiseId', (string) $franchiseInOtherCity->id)
            ->set('name', 'Wrong City Zone')
            ->set('boundaryPolygonJson', json_encode([['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2], ['lat' => 3, 'lng' => 3]]))
            ->call('save')
            ->assertHasErrors(['permission']);
    }

    public function test_toggle_active_is_denied_without_permission(): void
    {
        $franchise = $this->makeFranchise();
        $zone = Zone::create([
            'franchise_id' => $franchise->id, 'name' => 'Existing Zone',
            'boundary_polygon' => [['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2], ['lat' => 3, 'lng' => 3]],
            'is_active' => true,
        ]);
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(Manage::class)->assertForbidden();

        $this->assertTrue($zone->fresh()->is_active);
    }

    public function test_delete_is_denied_without_permission(): void
    {
        $franchise = $this->makeFranchise();
        $zone = Zone::create([
            'franchise_id' => $franchise->id, 'name' => 'Existing Zone',
            'boundary_polygon' => [['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2], ['lat' => 3, 'lng' => 3]],
            'is_active' => true,
        ]);
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(Manage::class)->assertForbidden();

        $this->assertDatabaseHas('zones', ['id' => $zone->id]);
    }

    /**
     * Admin Command Center completion session, Geography + Maps phase
     * (2026-08-20) — RBAC_SCOPE_MATRIX.md documents zones.manage as
     * row-level scoped and every mutation above already correctly checks
     * it, but render()'s list never applied AuthorizationService::
     * scopeQuery() at all — a franchise-scoped grant could browse every
     * OTHER franchise's zones.
     */
    public function test_franchise_scoped_grant_sees_only_its_own_franchises_zones_in_the_list(): void
    {
        $mine = $this->makeFranchise();
        $other = $this->makeFranchise();
        Zone::create(['franchise_id' => $mine->id, 'name' => 'Mine Zone', 'boundary_polygon' => [['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2], ['lat' => 3, 'lng' => 3]], 'is_active' => true]);
        Zone::create(['franchise_id' => $other->id, 'name' => 'Other Zone', 'boundary_polygon' => [['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2], ['lat' => 3, 'lng' => 3]], 'is_active' => true]);

        $actor = $this->makeUserWithPermission('zones.manage', 'franchise', $mine->id);

        Livewire::actingAs($actor)->test(Manage::class)
            ->assertSee('Mine Zone')
            ->assertDontSee('Other Zone');
    }

    public function test_super_admin_bypasses_scope_entirely(): void
    {
        $franchise = $this->makeFranchise();
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('franchiseId', (string) $franchise->id)
            ->set('name', 'Super Admin Zone')
            ->set('boundaryPolygonJson', json_encode([['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2], ['lat' => 3, 'lng' => 3]]))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('zones', ['name' => 'Super Admin Zone']);
    }
}
