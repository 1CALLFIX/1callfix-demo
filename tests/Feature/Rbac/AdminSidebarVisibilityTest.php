<?php

namespace Tests\Feature\Rbac;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for a Phase 11 (Admin Menu/Settings completeness
 * audit) finding: the sidebar (resources/views/layouts/admin.blade.php)
 * previously rendered all 26 items unconditionally for any admin-panel
 * actor, regardless of role — a `support`-role user (seeded with only
 * dashboard.view/bookings.view/providers.view) saw the exact same menu as
 * Super Admin, including links to Payouts and Roles & Permissions, only
 * discovering the lack of access after clicking through to a 403. The
 * sidebar now filters each item by the SAME permission its target screen's
 * own mount() enforces (see ScreenViewAuthorizationTest) — this is
 * visibility only, not a second enforcement boundary; each screen still
 * gates itself independently.
 */
class AdminSidebarVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    public function test_actor_with_a_single_permission_only_sees_its_matching_nav_item(): void
    {
        $actor = $this->makeUserWithPermission('dashboard.view', 'global');

        $this->actingAs($actor)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertDontSee('Payouts')
            ->assertDontSee('Roles &amp; Permissions', false)
            ->assertDontSee('Settings');
    }

    public function test_super_admin_sees_every_nav_item(): void
    {
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('Payouts')
            ->assertSee('Roles &amp; Permissions', false)
            ->assertSee('Settings')
            ->assertSee('Categories')
            ->assertSee('Subcategories');
    }

    public function test_actor_with_only_dashboard_view_cannot_reach_a_hidden_screen_either(): void
    {
        $actor = $this->makeUserWithPermission('dashboard.view', 'global');

        Livewire::actingAs($actor)->test(\App\Livewire\Payouts\Manage::class)->assertForbidden();
    }
}
