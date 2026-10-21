<?php

namespace Tests\Feature\Rbac;

use App\Livewire\Roles\Manage as RolesManage;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/** Owner request 2026-10-21: what each role can do is edited from the admin panel, never hard-coded. */
class RolePermissionGridTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['uuid' => (string) Str::uuid(), 'name' => $role, 'phone' => '8'.fake()->unique()->numerify('#########'), 'role' => $role, 'status' => 'active']);
    }

    public function test_super_admin_adds_a_permission_and_holders_get_it_at_once(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        $operatorRole = Role::where('slug', 'operator')->firstOrFail();
        $operator = $this->user('operator');
        RoleAssignment::create(['user_id' => $operator->id, 'role_id' => $operatorRole->id, 'scope_type' => 'franchise', 'scope_id' => $franchise->id]);
        $this->assertFalse($operator->hasPermission('providers.view', ['franchise_id' => $franchise->id]));

        $ids = $operatorRole->permissions->pluck('id')->push(Permission::where('slug', 'providers.view')->value('id'))->map(fn ($id) => (string) $id)->all();

        Livewire::actingAs($this->user('super_admin'))->test(RolesManage::class)
            ->call('editPermissions', $operatorRole->id)
            ->set('editingPermissionIds', $ids)
            ->call('savePermissions')
            ->assertSet('flashType', 'success');

        $this->assertTrue($operator->fresh()->hasPermission('providers.view', ['franchise_id' => $franchise->id]));
        $this->assertTrue(ActivityLog::where('description', 'like', 'Role permissions changed:%')->exists());
    }

    public function test_only_a_super_admin_can_edit_and_the_super_admin_role_is_locked(): void
    {
        $operatorRole = Role::where('slug', 'operator')->firstOrFail();
        $hqAdmin = $this->user('country_admin');
        $rolesManager = Role::create(['name' => 'Role manager', 'slug' => 'role_manager', 'is_system' => false]);
        $rolesManager->permissions()->attach(Permission::where('slug', 'roles.manage')->value('id'));
        RoleAssignment::create(['user_id' => $hqAdmin->id, 'role_id' => $rolesManager->id, 'scope_type' => 'global', 'scope_id' => null]);

        Livewire::actingAs($hqAdmin)->test(RolesManage::class)
            ->call('editPermissions', $operatorRole->id)
            ->assertForbidden();

        Livewire::actingAs($this->user('super_admin'))->test(RolesManage::class)
            ->call('editPermissions', Role::where('slug', 'super_admin')->value('id'))
            ->assertStatus(422);
    }

    public function test_a_wide_scope_permission_cannot_be_added_to_a_role_held_at_franchise_scope(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        $operatorRole = Role::where('slug', 'operator')->firstOrFail();
        RoleAssignment::create(['user_id' => $this->user('operator')->id, 'role_id' => $operatorRole->id, 'scope_type' => 'franchise', 'scope_id' => $franchise->id]);
        $before = $operatorRole->permissions->pluck('id')->sort()->values()->all();

        Livewire::actingAs($this->user('super_admin'))->test(RolesManage::class)
            ->call('editPermissions', $operatorRole->id)
            ->set('editingPermissionIds', array_merge(array_map('strval', $before), [(string) Permission::where('slug', 'zones.manage')->value('id')]))
            ->call('savePermissions')
            ->assertSet('flashType', 'error');

        $this->assertSame($before, $operatorRole->fresh()->permissions->pluck('id')->sort()->values()->all());
    }

    public function test_super_admin_creates_a_new_role_and_can_assign_it(): void
    {
        Livewire::actingAs($this->user('super_admin'))->test(RolesManage::class)
            ->set('newRoleName', 'HQ Finance')
            ->set('newRoleDescription', 'Approves refunds and waivers')
            ->call('createRole')
            ->assertSet('flashType', 'success')
            ->assertSet('editingRoleId', Role::where('slug', 'hq_finance')->value('id'));

        $this->assertDatabaseHas('roles', ['slug' => 'hq_finance', 'is_system' => false]);
    }
}
