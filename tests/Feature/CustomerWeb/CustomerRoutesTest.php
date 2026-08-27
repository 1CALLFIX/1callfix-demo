<?php

namespace Tests\Feature\CustomerWeb;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Customer web routing and the guest-redirect split (Phase B).
 *
 * The redirect assertions are the important ones. bootstrap/app.php's
 * redirectGuestsTo() previously sent EVERY unauthenticated guest to
 * admin.login; this suite pins both halves of the new path-aware
 * behaviour so a future edit cannot silently drop a customer onto the
 * staff email/password screen (or, worse, the reverse).
 */
class CustomerRoutesTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    public static function publicRouteProvider(): array
    {
        return [
            'home' => ['customer.home'],
            'login' => ['customer.login'],
            'help' => ['customer.help'],
            'how it works' => ['customer.how-it-works'],
        ];
    }

    #[DataProvider('publicRouteProvider')]
    public function test_public_routes_are_reachable_without_authentication(string $routeName): void
    {
        $this->get(route($routeName))->assertOk();
    }

    public function test_the_homepage_renders_for_a_guest_with_an_empty_catalog(): void
    {
        // No categories or services seeded — the page must still render its
        // shell and its empty states rather than erroring.
        $this->get(route('customer.home'))
            ->assertOk()
            ->assertSeeText('What do you need help with?');
    }

    public function test_the_homepage_renders_for_an_authenticated_customer(): void
    {
        $this->actingAs($this->makeCustomer())
            ->get(route('customer.home'))
            ->assertOk()
            ->assertSeeText('What do you need help with?');
    }

    // ==================== Guest redirect split ====================

    public function test_a_guest_reaching_a_customer_route_is_sent_to_the_customer_login(): void
    {
        $this->get(route('customer.account'))
            ->assertRedirect(route('customer.login'));
    }

    public function test_a_guest_reaching_an_admin_route_still_goes_to_the_admin_login(): void
    {
        $this->get('/admin/dashboard')
            ->assertRedirect(route('admin.login'));
    }

    public function test_an_authenticated_customer_can_open_their_account(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer)
            ->get(route('customer.account'))
            ->assertOk()
            ->assertSeeText($customer->phone);
    }

    /**
     * A plain customer holds no role_assignments, so EnsureHasAdminAccess
     * must keep them out of the admin panel even though they now have a
     * valid `web` session. Customer login must not become an admin backdoor.
     */
    public function test_a_logged_in_customer_still_cannot_reach_the_admin_panel(): void
    {
        $this->actingAs($this->makeCustomer())
            ->get('/admin/dashboard')
            ->assertForbidden();
    }

    // ==================== Coming-soon placeholder ====================

    public function test_known_coming_soon_features_render(): void
    {
        foreach (\App\Http\Controllers\Customer\PageController::COMING_SOON_FEATURES as $feature) {
            $this->get(route('customer.coming-soon', $feature))->assertOk();
        }
    }

    /**
     * The feature segment is whitelisted on the route, so an arbitrary
     * value can never be reflected back into the rendered page.
     */
    public function test_an_unknown_coming_soon_feature_is_a_404(): void
    {
        $this->get('/coming-soon/%3Cscript%3E')->assertNotFound();
    }

    // ==================== PWA foundation ====================

    public function test_the_manifest_and_icons_are_served(): void
    {
        $this->assertFileExists(public_path('manifest.webmanifest'));
        $this->assertFileExists(public_path('icons/icon.svg'));
        $this->assertFileExists(public_path('icons/icon-maskable.svg'));

        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);

        $this->assertIsArray($manifest, 'The manifest must be valid JSON or browsers ignore it entirely.');
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/', $manifest['start_url']);

        // Chrome requires at least one usable icon for installability, and a
        // separate maskable one so Android does not crop the mark.
        $purposes = array_column($manifest['icons'], 'purpose');
        $this->assertContains('any', $purposes);
        $this->assertContains('maskable', $purposes);
    }

    public function test_the_layout_declares_the_pwa_and_accessibility_foundations(): void
    {
        $response = $this->get(route('customer.home'))->assertOk();

        // viewport-fit=cover is what makes env(safe-area-inset-*) resolve on
        // notched devices; without it the fixed bottom nav sits under the
        // iOS home indicator.
        $response->assertSee('viewport-fit=cover', escape: false);
        $response->assertSee('manifest.webmanifest', escape: false);
        $response->assertSee('name="theme-color"', escape: false);
        $response->assertSee('Skip to main content');
        $response->assertSee('<main id="customer-main"', escape: false);
    }
}
