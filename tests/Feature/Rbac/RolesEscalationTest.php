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
}
