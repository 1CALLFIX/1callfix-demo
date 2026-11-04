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

    /** Partner apps 2026-11-04: the hamburger drawer became an app-style bottom bar on every screen size. */
    public function test_the_dashboard_shows_the_bottom_bar_with_home_jobs_earnings_and_more(): void
    {
        $response = $this->actingAs($this->providerUser())->get(route('provider.dashboard'));

        $response->assertOk();
        $response->assertSee('data-testid="partner-bottom-nav"', false);
        foreach (['provider.dashboard', 'provider.jobs.index', 'provider.earnings', 'provider.more'] as $route) {
            $response->assertSee(route($route), false);
        }
        $response->assertDontSee('Open menu', false);
    }

    /** Every section that is not a daily tab is one tap away on More — nothing unreachable on a phone. */
    public function test_every_provider_section_is_reachable_from_the_more_screen(): void
    {
        $response = $this->actingAs($this->providerUser())->get(route('provider.more'));

        $response->assertOk();
        $response->assertSee('data-testid="partner-more"', false);
        foreach (['provider.history', 'provider.activity', 'provider.wallet', 'provider.payment-accounts', 'provider.request-payout'] as $route) {
            $response->assertSee(route($route), false);
        }
        $response->assertSee('Job history');
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

    public function test_sign_out_is_reachable_from_the_more_screen(): void
    {
        $response = $this->actingAs($this->providerUser())->get(route('provider.more'));

        $response->assertOk();
        $response->assertSee(route('provider.logout'), false);
    }
}
