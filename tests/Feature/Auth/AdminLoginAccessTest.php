<?php

namespace Tests\Feature\Auth;

use App\Livewire\Auth\Login;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for the fix confirmed this session (see
 * EXACT_NEXT_TASK.md / CURRENT_MASTER_CHECKPOINT.md): Auth\Login::submit()
 * hard-rejected every non-super_admin users.role, even though
 * EnsureHasAdminAccess (the middleware guarding every actual /admin route)
 * was already updated to admit "super_admin OR holds at least one
 * role_assignment" — the login screen's own inline check was simply never
 * updated to match, so a real Country/City/Zone Admin, Franchise Owner,
 * Operator, or Support user could authenticate correctly and still never
 * reach the panel they were provisioned for. Fixed by mirroring
 * EnsureHasAdminAccess's exact predicate.
 */
class AdminLoginAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithPassword(array $overrides = []): User
    {
        return User::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'name' => 'Login Test User',
            'email' => Str::random(10).'@example.test',
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'password' => Hash::make('correct-password'),
            'role' => 'customer',
            'status' => 'active',
        ], $overrides));
    }

    private function grantRoleAssignment(User $user, string $permissionSlug = 'dashboard.view'): void
    {
        $permission = Permission::firstOrCreate(['slug' => $permissionSlug], ['label' => $permissionSlug, 'group' => 'Test']);
        $role = Role::create([
            'name' => 'Test Scoped Role',
            'slug' => 'test-scoped-role-'.Str::random(8),
            'description' => 'Test-only role',
            'is_system' => false,
        ]);
        $role->permissions()->attach($permission->id);

        RoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => 'global',
            'scope_id' => null,
        ]);
    }

    public function test_super_admin_can_still_log_in_unchanged_behavior(): void
    {
        $user = $this->makeUserWithPassword(['role' => 'super_admin']);

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('submit')
            ->assertRedirect(route('admin.dashboard'));

        $this->assertTrue(Auth::check());
    }

    public function test_a_user_with_no_role_assignments_and_no_super_admin_role_is_still_rejected(): void
    {
        $user = $this->makeUserWithPassword(['role' => 'customer']);

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('submit')
            ->assertSet('error', 'This account does not have admin access.');

        $this->assertFalse(Auth::check());
    }

    public function test_a_scoped_admin_with_a_real_role_assignment_can_now_log_in(): void
    {
        $user = $this->makeUserWithPassword(['role' => 'franchise_owner']);
        $this->grantRoleAssignment($user, 'dashboard.view');

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('submit')
            ->assertRedirect(route('admin.dashboard'));

        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());
    }

    public function test_wrong_password_is_still_rejected_before_any_role_check(): void
    {
        $user = $this->makeUserWithPassword(['role' => 'super_admin']);

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('submit')
            ->assertSet('error', 'Invalid email or password.');

        $this->assertFalse(Auth::check());
    }
}
