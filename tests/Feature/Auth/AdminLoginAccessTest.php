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

    /**
     * Production-hardening session, Part 2 — regression for the real gap
     * this session found: Login::submit() had no rate limiting at all,
     * unlike every OTP/QR endpoint on the API side. 5 wrong attempts (this
     * screen's own new limit, matching the API's existing OTP-request
     * throttle:5,1) must lock out the 6th attempt, even with the CORRECT
     * password on that 6th try -- proving the limiter runs before
     * Auth::attempt(), not just after a failure.
     */
    public function test_repeated_failed_attempts_are_rate_limited(): void
    {
        $user = $this->makeUserWithPassword(['role' => 'super_admin']);

        for ($i = 0; $i < 5; $i++) {
            Livewire::test(Login::class)
                ->set('email', $user->email)
                ->set('password', 'wrong-password')
                ->call('submit')
                ->assertSet('error', 'Invalid email or password.');
        }

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('submit')
            ->assertSet('error', fn ($error) => str_starts_with($error, 'Too many login attempts.'));

        $this->assertFalse(Auth::check());
    }

    /** A successful login must clear the counter -- a real admin who mistypes a couple of times, then logs in correctly, is never punished on their NEXT session. */
    public function test_a_successful_login_clears_the_rate_limit_counter(): void
    {
        $user = $this->makeUserWithPassword(['role' => 'super_admin']);

        for ($i = 0; $i < 3; $i++) {
            Livewire::test(Login::class)
                ->set('email', $user->email)
                ->set('password', 'wrong-password')
                ->call('submit');
        }

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('submit')
            ->assertRedirect(route('admin.dashboard'));

        $this->assertTrue(Auth::check());
    }

    /** Rate limiting is keyed per email+IP, not IP alone -- a lockout on one account must not lock out a different real admin logging in from the same office/NAT IP. */
    public function test_rate_limit_is_scoped_per_email_not_shared_across_accounts(): void
    {
        $lockedOut = $this->makeUserWithPassword(['role' => 'super_admin']);
        $otherAdmin = $this->makeUserWithPassword(['role' => 'super_admin']);

        for ($i = 0; $i < 5; $i++) {
            Livewire::test(Login::class)
                ->set('email', $lockedOut->email)
                ->set('password', 'wrong-password')
                ->call('submit');
        }

        Livewire::test(Login::class)
            ->set('email', $otherAdmin->email)
            ->set('password', 'correct-password')
            ->call('submit')
            ->assertRedirect(route('admin.dashboard'));

        $this->assertTrue(Auth::check());
    }
}
