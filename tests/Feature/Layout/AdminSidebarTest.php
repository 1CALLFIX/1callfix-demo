<?php

namespace Tests\Feature\Layout;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Sidebar Reorganization session — regression coverage for what's actually
 * server-rendered (grouping, permission filtering, active-group detection,
 * aria-expanded scaffolding). Per-viewer localStorage collapse persistence
 * is real client-side JS behaviour this repo has no browser-test tooling
 * (no Dusk, no Node/JS test runner — see KNOWN_RISKS_AND_DECISIONS.md) to
 * exercise; that JS is covered by code review, not this suite — documented
 * honestly rather than faking coverage with a server-only proxy assertion.
 */
class AdminSidebarTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    public function test_landing_on_a_finance_page_marks_finance_as_the_active_group(): void
    {
        $admin = $this->makeUserWithPermission('payouts.manage', 'global');
        $this->grantPermission($admin, 'dashboard.view');

        $response = $this->actingAs($admin)->get(route('admin.payouts.index'));

        $response->assertOk();
        $response->assertSee('data-active-nav-group="finance"', false);
    }

    public function test_landing_on_a_geography_page_marks_geography_as_the_active_group_not_finance(): void
    {
        $admin = $this->makeUserWithPermission('zones.manage', 'global');
        $this->grantPermission($admin, 'dashboard.view');

        $response = $this->actingAs($admin)->get(route('admin.zones.index'));

        $response->assertOk();
        $response->assertSee('data-active-nav-group="geography"', false);
        $response->assertDontSee('data-active-nav-group="finance"', false);
    }

    public function test_other_verticals_group_is_muted_and_labelled_pre_launch(): void
    {
        $admin = $this->makeUserWithPermission('parcel_orders.view', 'global');
        $this->grantPermission($admin, 'dashboard.view');

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('data-group-key="other_verticals"', false);
        $response->assertSee('pre-launch', false);
        $response->assertSee('Parcel Orders');
    }

    public function test_a_group_with_zero_visible_items_for_this_actor_is_not_rendered_at_all(): void
    {
        // Holds ONLY dashboard.view -- no Finance-group permission at all,
        // so the whole Finance group (trigger + panel) must be absent, not
        // rendered-but-empty. Checking aria-controls (a real DOM-only
        // marker for the Finance trigger actually being rendered), not the
        // bare "finance" key text — the <style> block's own CSS selectors
        // legitimately mention every possible group key regardless of
        // which ones this viewer can see.
        $admin = $this->makeUserWithPermission('dashboard.view', 'global');

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertDontSee('aria-controls="nav-group-panel-finance"', false);
    }

    public function test_collapsible_group_triggers_are_real_buttons_with_aria_expanded(): void
    {
        $admin = $this->makeUserWithPermission('payouts.manage', 'global');
        $this->grantPermission($admin, 'dashboard.view');

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('nav-group-trigger', false);
        $response->assertSee('aria-expanded=', false);
        $response->assertSee('aria-controls="nav-group-panel-finance"', false);
    }

    public function test_dashboard_overview_item_is_never_wrapped_in_a_collapsible_trigger(): void
    {
        $admin = $this->makeUserWithPermission('dashboard.view', 'global');

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertDontSee('aria-controls="nav-group-panel-overview"', false);
    }

    /**
     * Quick Fix session — the "All Users" directory (Unified Directory
     * session) link, added to the Operations group alongside Bookings/
     * Customers/Providers/Workers. Same gate as every other item: absent
     * entirely for a viewer without users.directory.view, present and
     * correctly linked for one who holds it — no special-casing.
     */
    public function test_all_users_link_appears_for_a_viewer_with_the_directory_permission(): void
    {
        $admin = $this->makeUserWithPermission('users.directory.view', 'global');
        $this->grantPermission($admin, 'dashboard.view');

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('All Users');
        $response->assertSee(route('admin.all-users.index'), false);
    }

    public function test_all_users_link_is_absent_for_a_viewer_without_the_directory_permission(): void
    {
        $admin = $this->makeUserWithPermission('dashboard.view', 'global');

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertDontSee('All Users');
    }
}
