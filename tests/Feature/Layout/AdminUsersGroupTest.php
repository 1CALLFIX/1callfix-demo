<?php

namespace Tests\Feature\Layout;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Users Sidebar Reorganization session — Customers/Providers/Workers/All
 * Users moved out of "Operations" into their own "Users" group, plus a new
 * reserved "Drivers" slot for the Parcel vertical's future riders (see
 * App\Livewire\Drivers\Index's own docblock — no real functionality
 * exists behind it). This is a pure relocation: every permission slug is
 * unchanged from before the move, so customers.view/workers.view/the new
 * drivers.view stay locked to super_admin exactly as seeded.
 */
class AdminUsersGroupTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    public function test_super_admin_sees_the_users_group_with_all_five_sub_branches(): void
    {
        $admin = $this->makeSuperAdmin();

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('data-group-key="users"', false);
        $response->assertSee('aria-controls="nav-group-panel-users"', false);
        $response->assertSee('All Users');
        $response->assertSee('Customers');
        $response->assertSee('Providers');
        $response->assertSee('Drivers');
        $response->assertSee('Workers');
        $response->assertSee(route('admin.customers.index'), false);
        $response->assertSee(route('admin.providers.index'), false);
        $response->assertSee(route('admin.drivers.index'), false);
        $response->assertSee(route('admin.workers.index'), false);
        $response->assertSee(route('admin.all-users.index'), false);
    }

    public function test_landing_on_drivers_marks_users_as_the_active_group(): void
    {
        $admin = $this->makeSuperAdmin();

        $response = $this->actingAs($admin)->get(route('admin.drivers.index'));

        $response->assertOk();
        $response->assertSee('data-active-nav-group="users"', false);
    }

    public function test_a_viewer_with_only_providers_view_sees_providers_but_no_other_users_sub_branch(): void
    {
        $actor = $this->makeUserWithPermission('providers.view', 'global');
        $this->grantPermission($actor, 'dashboard.view');

        $response = $this->actingAs($actor)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Providers');
        $response->assertDontSee('Customers');
        $response->assertDontSee('Drivers');
        $response->assertDontSee('Workers');
        $response->assertDontSee('All Users');
    }

    /**
     * The real seeded `support` role (2026_08_11_016000) holds
     * providers.view but NOT customers.view/workers.view — proving the
     * pre-existing super_admin-only scoping on those two survived being
     * relocated into the new group, using an actual system role rather
     * than a synthetic test-only permission grant.
     */
    public function test_real_support_role_actor_cannot_see_super_admin_only_users_sub_branches(): void
    {
        $supportRole = Role::where('slug', 'support')->firstOrFail();

        $actor = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Support Agent',
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'support',
            'status' => 'active',
        ]);

        RoleAssignment::create([
            'user_id' => $actor->id,
            'role_id' => $supportRole->id,
            'scope_type' => 'global',
            'scope_id' => null,
        ]);

        $response = $this->actingAs($actor)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Providers');
        $response->assertDontSee('Customers');
        $response->assertDontSee('Drivers');
        $response->assertDontSee('Workers');
    }

    public function test_drivers_placeholder_is_open_to_super_admin_and_forbidden_otherwise(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $nonAdmin = $this->makeUserWithPermission('dashboard.view', 'global');

        Livewire::actingAs($superAdmin)->test(\App\Livewire\Drivers\Index::class)
            ->assertOk()
            ->assertSee('Drivers');

        Livewire::actingAs($nonAdmin)->test(\App\Livewire\Drivers\Index::class)->assertForbidden();
    }
}
