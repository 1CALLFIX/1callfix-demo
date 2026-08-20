<?php

namespace Tests\Feature\Rbac;

use App\Livewire\Roles\Manage;
use App\Models\Role;
use App\Models\RoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for 21a1fcd: Roles\Manage::assign()/revoke() had zero
 * authorization checks, so any single-scope admin-panel actor could grant
 * themselves (or anyone) super_admin at global scope. That fix had no
 * automated test — this locks it in.
 */
class RolesEscalationTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    public function test_actor_without_roles_manage_cannot_grant_super_admin(): void
    {
        $actor = $this->makeUserWithNoPermissions();
        $target = $this->makeUserWithNoPermissions();
        $superAdminRole = Role::where('slug', 'super_admin')->firstOrFail();

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('selectedUserId', $target->id)
            ->set('roleId', $superAdminRole->id)
            ->set('scopeType', 'global')
            ->call('assign');

        $this->assertDatabaseMissing('role_assignments', [
            'user_id' => $target->id, 'role_id' => $superAdminRole->id, 'scope_type' => 'global',
        ]);
    }

    public function test_franchise_scoped_roles_manage_can_grant_within_own_franchise(): void
    {
        $franchise = $this->makeFranchise();
        $actor = $this->makeUserWithPermission('roles.manage', 'franchise', $franchise->id);
        $target = $this->makeUserWithNoPermissions();
        $someRole = Role::where('slug', 'operator')->firstOrFail();

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('selectedUserId', $target->id)
            ->set('roleId', $someRole->id)
            ->set('scopeType', 'franchise')
            ->set('scopeFranchiseId', $franchise->id)
            ->call('assign');

        $this->assertDatabaseHas('role_assignments', [
            'user_id' => $target->id, 'role_id' => $someRole->id,
            'scope_type' => 'franchise', 'scope_id' => $franchise->id,
        ]);
    }

    public function test_franchise_scoped_roles_manage_cannot_escalate_to_global(): void
    {
        $franchise = $this->makeFranchise();
        $actor = $this->makeUserWithPermission('roles.manage', 'franchise', $franchise->id);
        $target = $this->makeUserWithNoPermissions();
        $superAdminRole = Role::where('slug', 'super_admin')->firstOrFail();

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('selectedUserId', $target->id)
            ->set('roleId', $superAdminRole->id)
            ->set('scopeType', 'global')
            ->call('assign');

        $this->assertDatabaseMissing('role_assignments', [
            'user_id' => $target->id, 'role_id' => $superAdminRole->id, 'scope_type' => 'global',
        ]);
    }

    public function test_franchise_scoped_roles_manage_cannot_grant_in_another_franchise(): void
    {
        $ownFranchise = $this->makeFranchise();
        $otherFranchise = $this->makeFranchise();
        $actor = $this->makeUserWithPermission('roles.manage', 'franchise', $ownFranchise->id);
        $target = $this->makeUserWithNoPermissions();
        $someRole = Role::where('slug', 'operator')->firstOrFail();

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('selectedUserId', $target->id)
            ->set('roleId', $someRole->id)
            ->set('scopeType', 'franchise')
            ->set('scopeFranchiseId', $otherFranchise->id)
            ->call('assign');

        $this->assertDatabaseMissing('role_assignments', [
            'user_id' => $target->id, 'role_id' => $someRole->id,
            'scope_type' => 'franchise', 'scope_id' => $otherFranchise->id,
        ]);
    }

    public function test_actor_without_roles_manage_cannot_revoke_an_assignment(): void
    {
        $franchise = $this->makeFranchise();
        $target = $this->makeUserWithPermission('bookings.create', 'franchise', $franchise->id);
        $assignment = RoleAssignment::where('user_id', $target->id)->firstOrFail();

        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('confirmingRevokeId', $assignment->id)
            ->call('revoke');

        $this->assertDatabaseHas('role_assignments', ['id' => $assignment->id]);
    }

    public function test_super_admin_can_grant_super_admin_at_global_scope(): void
    {
        $admin = $this->makeSuperAdmin();
        $target = $this->makeUserWithNoPermissions();
        $superAdminRole = Role::where('slug', 'super_admin')->firstOrFail();

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('selectedUserId', $target->id)
            ->set('roleId', $superAdminRole->id)
            ->set('scopeType', 'global')
            ->call('assign');

        $this->assertDatabaseHas('role_assignments', [
            'user_id' => $target->id, 'role_id' => $superAdminRole->id, 'scope_type' => 'global',
        ]);
    }

    // ======== Platform-structure grant-scope policy (follow-up to item 51, 2026-08-20) ========
    //
    // modules.manage/zones.manage/franchises.manage must never be
    // assignable below their own restricted grant scope (see
    // AuthorizationService::RESTRICTED_GRANT_SCOPES's own docblock for
    // exactly why each threshold is where it is), regardless of whether
    // the ACTOR performing the assignment holds unrestricted roles.manage
    // access -- this is a constraint on the assignment being created, not
    // a permission check on who's creating it.

    public function test_country_admin_role_cannot_be_assigned_at_franchise_scope(): void
    {
        $admin = $this->makeSuperAdmin();
        $franchise = $this->makeFranchise();
        $target = $this->makeUserWithNoPermissions();
        $countryAdminRole = Role::where('slug', 'country_admin')->firstOrFail();

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('selectedUserId', $target->id)
            ->set('roleId', $countryAdminRole->id)
            ->set('scopeType', 'franchise')
            ->set('scopeFranchiseId', $franchise->id)
            ->call('assign');

        $this->assertDatabaseMissing('role_assignments', [
            'user_id' => $target->id, 'role_id' => $countryAdminRole->id,
            'scope_type' => 'franchise', 'scope_id' => $franchise->id,
        ]);
    }

    /**
     * country_admin's own seeded permission set carries BOTH zones.manage
     * and franchises.manage -- this proves the assignment-layer check
     * doesn't just look at the FIRST restricted permission a role happens
     * to carry; it must correctly allow scope_type='country', the exact
     * scope this role was designed for, unaffected by this policy pass.
     */
    public function test_country_admin_role_can_still_be_assigned_at_country_scope(): void
    {
        $admin = $this->makeSuperAdmin();
        $country = $this->makeCountry();
        $target = $this->makeUserWithNoPermissions();
        $countryAdminRole = Role::where('slug', 'country_admin')->firstOrFail();

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('selectedUserId', $target->id)
            ->set('roleId', $countryAdminRole->id)
            ->set('scopeType', 'country')
            ->set('scopeCountryId', $country->id)
            ->call('assign');

        $this->assertDatabaseHas('role_assignments', [
            'user_id' => $target->id, 'role_id' => $countryAdminRole->id,
            'scope_type' => 'country', 'scope_id' => $country->id,
        ]);
    }

    public function test_city_admin_role_cannot_be_assigned_at_zone_scope(): void
    {
        $admin = $this->makeSuperAdmin();
        $franchise = $this->makeFranchise();
        $zone = \App\Models\Zone::create([
            'franchise_id' => $franchise->id, 'name' => 'Z',
            'boundary_polygon' => [['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2], ['lat' => 3, 'lng' => 3]],
            'is_active' => true,
        ]);
        $target = $this->makeUserWithNoPermissions();
        $cityAdminRole = Role::where('slug', 'city_admin')->firstOrFail();

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('selectedUserId', $target->id)
            ->set('roleId', $cityAdminRole->id)
            ->set('scopeType', 'zone')
            ->set('scopeZoneId', $zone->id)
            ->call('assign');

        $this->assertDatabaseMissing('role_assignments', [
            'user_id' => $target->id, 'role_id' => $cityAdminRole->id,
            'scope_type' => 'zone', 'scope_id' => $zone->id,
        ]);
    }

    public function test_city_admin_role_can_still_be_assigned_at_city_scope(): void
    {
        $admin = $this->makeSuperAdmin();
        $city = $this->makeCity();
        $target = $this->makeUserWithNoPermissions();
        $cityAdminRole = Role::where('slug', 'city_admin')->firstOrFail();

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('selectedUserId', $target->id)
            ->set('roleId', $cityAdminRole->id)
            ->set('scopeType', 'city')
            ->set('scopeCityId', $city->id)
            ->call('assign');

        $this->assertDatabaseHas('role_assignments', [
            'user_id' => $target->id, 'role_id' => $cityAdminRole->id,
            'scope_type' => 'city', 'scope_id' => $city->id,
        ]);
    }

    /**
     * No seeded system role below super_admin carries modules.manage, so
     * this creates a real (non-system) role to prove the restriction
     * itself -- modules.manage's own allowed grant scope is global only,
     * stricter than zones.manage/franchises.manage's global/country/city.
     */
    public function test_a_role_carrying_modules_manage_cannot_be_assigned_below_global_scope(): void
    {
        $admin = $this->makeSuperAdmin();
        $country = $this->makeCountry();
        $target = $this->makeUserWithNoPermissions();

        $customRole = Role::create(['name' => 'Test Module Manager', 'slug' => 'test_module_manager_'.\Illuminate\Support\Str::random(6), 'is_system' => false]);
        $customRole->permissions()->attach(\App\Models\Permission::where('slug', 'modules.manage')->firstOrFail());

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('selectedUserId', $target->id)
            ->set('roleId', $customRole->id)
            ->set('scopeType', 'country')
            ->set('scopeCountryId', $country->id)
            ->call('assign');

        $this->assertDatabaseMissing('role_assignments', [
            'user_id' => $target->id, 'role_id' => $customRole->id,
        ]);
    }

    public function test_a_role_carrying_modules_manage_can_be_assigned_at_global_scope(): void
    {
        $admin = $this->makeSuperAdmin();
        $target = $this->makeUserWithNoPermissions();

        $customRole = Role::create(['name' => 'Test Module Manager', 'slug' => 'test_module_manager_'.\Illuminate\Support\Str::random(6), 'is_system' => false]);
        $customRole->permissions()->attach(\App\Models\Permission::where('slug', 'modules.manage')->firstOrFail());

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('selectedUserId', $target->id)
            ->set('roleId', $customRole->id)
            ->set('scopeType', 'global')
            ->call('assign');

        $this->assertDatabaseHas('role_assignments', [
            'user_id' => $target->id, 'role_id' => $customRole->id, 'scope_type' => 'global',
        ]);
    }

    /**
     * The super_admin ROLE itself carries every permission, including
     * modules.manage -- this proves assigning that ROLE (not the
     * users.role='super_admin' column, a separate legacy mechanism) at a
     * narrower scope is caught by the same restriction, closing an even
     * more dangerous variant of the original gap (a "super admin, but
     * scoped to one franchise" grant).
     */
    public function test_super_admin_role_itself_cannot_be_assigned_at_franchise_scope(): void
    {
        $admin = $this->makeSuperAdmin();
        $franchise = $this->makeFranchise();
        $target = $this->makeUserWithNoPermissions();
        $superAdminRole = Role::where('slug', 'super_admin')->firstOrFail();

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('selectedUserId', $target->id)
            ->set('roleId', $superAdminRole->id)
            ->set('scopeType', 'franchise')
            ->set('scopeFranchiseId', $franchise->id)
            ->call('assign');

        $this->assertDatabaseMissing('role_assignments', [
            'user_id' => $target->id, 'role_id' => $superAdminRole->id,
            'scope_type' => 'franchise', 'scope_id' => $franchise->id,
        ]);
    }
}
