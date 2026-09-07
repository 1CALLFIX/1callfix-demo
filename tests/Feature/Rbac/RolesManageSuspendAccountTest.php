<?php

namespace Tests\Feature\Rbac;

use App\Livewire\Roles\Manage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Enable/Disable Audit gap fix — Roles\Manage::toggleUserSuspended(). Before
 * this, an admin could create a staff account and grant/revoke individual
 * RoleAssignment rows, but there was no way to suspend the ACCOUNT itself;
 * the only lever was revoking every RoleAssignment one at a time, which
 * strips permissions but leaves the account able to authenticate. Reuses
 * users.status, the same column Customers\Show::toggleSuspended() already
 * writes — see SuspendedAccountCannotLoginTest for proof this status now
 * actually blocks login.
 */
class RolesManageSuspendAccountTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    private function makeTargetUser(string $status = 'active'): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Target Staff',
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'operator',
            'status' => $status,
        ]);
    }

    public function test_global_roles_manage_actor_can_suspend_an_account(): void
    {
        $actor = $this->makeUserWithPermission('roles.manage', 'global');
        $target = $this->makeTargetUser();

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('selectedUserId', $target->id)
            ->call('toggleUserSuspended')
            ->assertSet('flashType', 'success');

        $this->assertSame('suspended', $target->fresh()->status);
    }

    public function test_suspending_again_reactivates_it(): void
    {
        $actor = $this->makeUserWithPermission('roles.manage', 'global');
        $target = $this->makeTargetUser('suspended');

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('selectedUserId', $target->id)
            ->call('toggleUserSuspended')
            ->assertSet('flashType', 'success');

        $this->assertSame('active', $target->fresh()->status);
    }

    public function test_franchise_scoped_roles_manage_cannot_suspend_anyone(): void
    {
        $franchise = $this->makeFranchise();
        $actor = $this->makeUserWithPermission('roles.manage', 'franchise', $franchise->id);
        $target = $this->makeTargetUser();

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('selectedUserId', $target->id)
            ->call('toggleUserSuspended')
            ->assertSet('flashType', 'error');

        $this->assertSame('active', $target->fresh()->status);
    }

    public function test_actor_with_no_roles_manage_cannot_suspend(): void
    {
        $actor = $this->makeUserWithNoPermissions();
        $target = $this->makeTargetUser();

        // Roles\Manage::mount() itself hard-aborts(403) any actor without
        // roles.manage -- same reasoning as RolesEscalationTest's identical
        // mount-time-abort case.
        Livewire::actingAs($actor)->test(Manage::class)->assertForbidden();

        $this->assertSame('active', $target->fresh()->status);
    }

    public function test_no_user_selected_is_a_no_op_with_an_error(): void
    {
        $actor = $this->makeUserWithPermission('roles.manage', 'global');

        Livewire::actingAs($actor)->test(Manage::class)
            ->call('toggleUserSuspended')
            ->assertSet('flashType', 'error')
            ->assertSet('flashMessage', 'Select a user first.');
    }

    /** Safety guard added alongside this fix: a global roles.manage holder cannot lock themselves out via this action. */
    public function test_actor_cannot_suspend_their_own_account(): void
    {
        $actor = $this->makeUserWithPermission('roles.manage', 'global');

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('selectedUserId', $actor->id)
            ->call('toggleUserSuspended')
            ->assertSet('flashType', 'error')
            ->assertSet('flashMessage', 'You cannot suspend your own account.');

        $this->assertSame('active', $actor->fresh()->status);
    }

    /** Regression: an untouched account's status/RoleAssignments are unaffected by this fix existing. */
    public function test_an_untouched_account_remains_active_and_keeps_its_role_assignments(): void
    {
        $actor = $this->makeUserWithPermission('roles.manage', 'global');
        $target = $this->makeTargetUser();
        $this->grantPermission($target, 'bookings.view', 'global');

        Livewire::actingAs($actor)->test(Manage::class); // just view the screen, touch nothing

        $target->refresh();
        $this->assertSame('active', $target->status);
        $this->assertTrue($target->roleAssignments()->exists());
    }
}
