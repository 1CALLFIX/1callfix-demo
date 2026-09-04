<?php

namespace Tests\Feature\ProviderWeb;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Provider Mobile Nav session — regression coverage for the gap this
 * session fixed: History and Activity were rendered `hidden sm:inline`
 * with no hamburger/drawer as an alternative, so under the `sm` breakpoint
 * they were flat unreachable. These tests assert every provider-facing
 * route (and the online/offline toggle) is present in the server-rendered
 * markup itself — the drawer's open/close is client-side Alpine state this
 * repo has no browser-test tooling for (see AdminSidebarTest's own
 * docblock on the same limitation for the admin sidebar's JS), but every
 * link it contains is unconditionally in the HTML regardless of viewport.
 */
class ProviderMobileNavTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function providerUser(): User
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();

        return $this->makeProviderIn($franchise, $zone)->user;
    }

    public function test_the_dashboard_response_contains_the_hamburger_and_drawer(): void
    {
        $response = $this->actingAs($this->providerUser())->get(route('provider.dashboard'));

        $response->assertOk();
        $response->assertSee('Open menu', false);
        $response->assertSee('Partner menu', false);
    }

    /**
     * The exact bug: History and Activity previously vanished below `sm`
     * with nothing replacing them. Now every route is unconditionally in
     * the markup (inline nav + drawer both render all five, CSS only
     * decides which copy is visible at a given width).
     */
    public function test_every_provider_section_is_reachable_from_the_rendered_markup(): void
    {
        $response = $this->actingAs($this->providerUser())->get(route('provider.dashboard'));

        $response->assertOk();
        $response->assertSee(route('provider.dashboard'), false);
        $response->assertSee(route('provider.jobs.index'), false);
        $response->assertSee(route('provider.earnings'), false);
        $response->assertSee(route('provider.history'), false);
        $response->assertSee(route('provider.activity'), false);

        $response->assertSee('Job Offers');
        $response->assertSee('History');
        $response->assertSee('Earnings');
        $response->assertSee('Activity');
    }

    /**
     * The online/offline toggle used to exist ONLY inside the Dashboard
     * page body — unreachable from any other provider page without
     * navigating back to `/provider` first. It's now mounted in the shared
     * layout header, so it must appear on a non-dashboard page too.
     */
    public function test_the_online_toggle_is_reachable_from_a_non_dashboard_page(): void
    {
        $response = $this->actingAs($this->providerUser())->get(route('provider.earnings'));

        $response->assertOk();
        // Fixture providers default to online, so assert on the component
        // itself rather than the "Go online"/"Go offline" button text,
        // which is state-dependent.
        $response->assertSee('wire:name="provider.online-toggle"', false);
    }

    public function test_sign_out_is_reachable_from_the_drawer_markup(): void
    {
        $response = $this->actingAs($this->providerUser())->get(route('provider.dashboard'));

        $response->assertOk();
        $response->assertSee(route('provider.logout'), false);
    }
}
