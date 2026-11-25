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

    /** Owner 2026-11-25: past jobs are on the Jobs tab itself (New offers | Job history), not only under More. */
    public function test_the_jobs_tab_links_to_job_history_and_history_keeps_the_jobs_tab_lit(): void
    {
        $user = $this->providerUser();

        $offers = $this->actingAs($user)->get(route('provider.jobs.index'));
        $offers->assertOk();
        $offers->assertSee('data-testid="partner-jobs-tabs"', false);
        $offers->assertSee(route('provider.history'), false);
        $offers->assertSee('See your past jobs');

        $history = $this->actingAs($user)->get(route('provider.history'));
        $history->assertOk();
        $history->assertSee('data-testid="partner-jobs-tabs"', false);
        $history->assertSee(route('provider.jobs.index'), false);
        // The bottom bar marks Jobs (not More) as the current tab on the history page.
        $this->assertMatchesRegularExpression(
            '#<a[^>]*href="'.preg_quote(route('provider.jobs.index'), '#').'"[^>]*aria-current="page"#s',
            $history->getContent(),
        );
    }

    /** History rows show the date, the amount and a coloured status badge. */
    public function test_job_history_shows_amount_and_status_badge(): void
    {
        ['provider' => $provider, 'booking' => $booking] = $this->makeAssignedBookingScenario();
        $booking->update(['status' => 'completed', 'price_quoted' => 499, 'completed_at' => now()]);

        $response = $this->actingAs($provider->user)->get(route('provider.history'));

        $response->assertOk();
        $response->assertSee($booking->code);
        $response->assertSee('₹499.00');
        $response->assertSee('bg-emerald-100', false);
    }

    public function test_sign_out_is_reachable_from_the_more_screen(): void
    {
        $response = $this->actingAs($this->providerUser())->get(route('provider.more'));

        $response->assertOk();
        $response->assertSee(route('provider.logout'), false);
    }
}
