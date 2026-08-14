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

    public function test_user_with_matching_franchise_scope_can_create_zone(): void
    {
        $franchise = $this->makeFranchise();
        $actor = $this->makeUserWithPermission('zones.manage', 'franchise', $franchise->id);

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
