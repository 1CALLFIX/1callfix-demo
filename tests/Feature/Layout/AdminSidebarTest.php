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

    public function test_each_module_has_its_own_group_and_pre_launch_ones_are_muted(): void
    {
        $admin = $this->makeUserWithPermission('parcel_orders.view', 'global');
        $this->grantPermission($admin, 'dashboard.view');
        $this->grantPermission($admin, 'taxi_rides.view');

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        // Parcel is live: its own group, not muted. Taxi is pre-launch: its own group, muted and labelled.
        $response->assertSee('data-group-key="parcel" data-prelaunch="0"', false);
        $response->assertSee('data-group-key="taxi" data-prelaunch="1"', false);
        $response->assertSee('pre-launch', false);
        $response->assertSee('Parcel Orders');
        $response->assertDontSee('data-group-key="other_verticals"', false);
    }

    public function test_every_admin_screen_route_in_the_old_menu_is_still_in_the_menu(): void
    {
        $routes = collect(\App\Support\AdminNav::groups())->flatMap(fn ($g) => $g['items'])->pluck('route')->unique();

        foreach ([
            'admin.dashboard', 'admin.all-users.index', 'admin.customers.index', 'admin.providers.index', 'admin.partners.index',
            'admin.bookings.index', 'admin.kyc.support-requests.index', 'admin.chat.index', 'admin.zones.index', 'admin.geography.index',
            'admin.franchises.index', 'admin.modules.index', 'admin.services.index', 'admin.categories.index', 'admin.subcategories.index',
            'admin.seo.settings', 'admin.seo.city-content', 'admin.banners.index', 'admin.home-spotlight.index', 'admin.site-controls',
            'admin.home-sections', 'admin.site-text', 'admin.search-box.index', 'admin.partner-page.settings', 'admin.partner-leads.index',
            'admin.badges.index', 'admin.flash-sales.index', 'admin.coupons.index', 'admin.performance-campaigns.index', 'admin.loyalty.index',
            'admin.plans.index', 'admin.subscriptions.index', 'admin.payouts.index', 'admin.wallet-ledger.index', 'admin.earnings-control.index',
            'admin.cancellation-policy.index', 'admin.commissions.index', 'admin.franchise-earnings.index', 'admin.payments.index',
            'admin.booking-disputes.index', 'admin.shop-returns.index', 'admin.parcel-claims.index', 'admin.charge-waivers.index',
            'admin.mismatch-refunds.index', 'admin.refund-controls.index', 'admin.payment-gateways.index', 'admin.notifications.index',
            'admin.cms.index', 'admin.parcel-orders.dashboard', 'admin.parcel-orders.index', 'admin.parcel-orders.settings',
            'admin.parcel-packs.index', 'admin.taxi-rides.index', 'admin.properties.index', 'admin.property-reservations.index',
            'admin.vehicles.index', 'admin.equipment.index', 'admin.rental-reservations.index', 'admin.accommodations.index',
            'admin.hotel-reservations.index', 'admin.stores.index', 'admin.marketplace-categories.index', 'admin.products.index',
            'admin.change-approvals.index', 'admin.add-ons.index', 'admin.marketplace-orders.index', 'admin.marketplace-orders.settings',
            'admin.food.dashboard', 'admin.food.settings', 'admin.shop-commission.index', 'admin.shop-tax-report', 'admin.operations.index',
            'admin.alert-emails.index', 'admin.operations.backup-restore', 'admin.operations.data-clear', 'admin.providers.availability',
            'admin.reviews.google', 'admin.security.headers', 'admin.roles.index', 'admin.settings.index',
        ] as $route) {
            $this->assertTrue($routes->contains($route), "{$route} is missing from the admin menu");
        }
    }

    public function test_food_restaurants_link_is_current_on_the_filtered_stores_screen_not_stores(): void
    {
        $admin = $this->makeSuperAdmin();

        $html = $this->actingAs($admin)->get(route('admin.stores.index', ['moduleFilter' => 'food']))->assertOk()->getContent();

        $this->assertStringContainsString('data-active-nav-group="food"', $html);
        $this->assertMatchesRegularExpression('/href="[^"]*moduleFilter=food"[^>]*aria-current="page"/', $html);
        $this->assertDoesNotMatchRegularExpression('/href="'.preg_quote(route('admin.stores.index'), '/').'"[^>]*aria-current="page"/', $html);
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

    /**
     * Payment Gateway Manager session — payment_gateways.manage is seeded
     * super_admin-only (unlike users.directory.view above, grantable at any
     * scope), but the sidebar's own visibility check is identical either
     * way: present for a holder, absent otherwise — same shape as every
     * other permission-gated link, no special-casing.
     */
    public function test_payment_gateways_link_appears_for_a_viewer_with_the_permission(): void
    {
        $admin = $this->makeUserWithPermission('payment_gateways.manage', 'global');
        $this->grantPermission($admin, 'dashboard.view');

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Payment Gateways');
        $response->assertSee(route('admin.payment-gateways.index'), false);
    }

    public function test_payment_gateways_link_is_absent_for_a_viewer_without_the_permission(): void
    {
        $admin = $this->makeUserWithPermission('dashboard.view', 'global');

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertDontSee('Payment Gateways');
    }
}
