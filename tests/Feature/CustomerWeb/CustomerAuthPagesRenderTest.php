<?php

namespace Tests\Feature\CustomerWeb;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * Full-page HTTP render coverage for every customer auth screen added in the
 * auth rebuild (feature/auth-password-rebuild).
 *
 * Why this file exists
 * --------------------
 * Production /login returned a hard HTTP 500 —
 * `RouteNotFoundException: Route [customer.password.forgot] not defined` —
 * while the whole test suite stayed green. The existing auth tests drive the
 * Livewire components in isolation (`Livewire::test(...)`), which renders the
 * component's own view but never the surrounding layout, never the `guest`
 * route middleware, and — crucially — never fails when a sibling page embeds
 * a `route('customer.*')` name that isn't registered.
 *
 * Only `/login` had a real end-to-end GET (CustomerAuthTest,
 * CustomerRoutesTest); `/signup`, `/forgot-password`, `/auth/set-password`
 * and `/auth/google` had none. This suite closes that gap and adds a static
 * reconciliation guard so a view referencing an unregistered route name
 * fails CI instead of shipping.
 */
class CustomerAuthPagesRenderTest extends TestCase
{
    use BookingFixtureHelpers;
    use RebuiltAuthHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeFirebase();
    }

    // ─────────────────── Guest full-page GET → 200 ────────────────────────

    /**
     * The three self-contained guest screens. A real request exercises the
     * `guest` middleware, the customer layout, and every `route()` call in
     * the page — the exact path that 500'd in production.
     */
    public static function guestRenderableAuthPages(): array
    {
        return [
            'login' => ['/login', 'customer.login'],
            'signup' => ['/signup', 'customer.signup'],
            'forgot password' => ['/forgot-password', 'customer.password.forgot'],
        ];
    }

    #[DataProvider('guestRenderableAuthPages')]
    public function test_auth_page_renders_for_a_guest_with_http_200(string $path, string $routeName): void
    {
        // Both the literal path and the named route must resolve — the
        // production failure was a name that the compiled route table
        // didn't carry.
        $this->assertTrue(Route::has($routeName), "Route [{$routeName}] is not registered.");
        $this->assertSame(url($path), route($routeName), "Route [{$routeName}] does not map to {$path}.");

        $this->get($path)->assertOk();
        $this->get(route($routeName))->assertOk();
    }

    // ─────────────── Screens that need a prerequisite to render ───────────

    /**
     * `/auth/set-password` (customer.auth.migrate) is a mid-flow screen: it
     * needs the identifier of a password-less account. `$identifier` is not
     * a `#[Url]` property, so a COLD full-page GET — bare or even with an
     * explicit `?identifier=` — cannot hydrate it and mount() bounces the
     * visitor to /login. It renders only when mounted as a Livewire child
     * with the param, which is how Login::redirectRoute() reaches it.
     *
     * These assertions pin the current behaviour so the redirect target
     * can't silently regress to admin.login (see CustomerRoutesTest). The
     * cold-load param gap is tracked separately.
     */
    public function test_password_migration_cold_get_bounces_to_login(): void
    {
        $legacy = $this->legacyPasswordlessCustomer();

        $this->get(route('customer.auth.migrate'))
            ->assertRedirect(route('customer.login'));

        $this->get(route('customer.auth.migrate', ['identifier' => $legacy->phone]))
            ->assertRedirect(route('customer.login'));
    }

    public function test_password_migration_renders_when_mounted_from_the_login_handoff(): void
    {
        $legacy = $this->legacyPasswordlessCustomer();

        \Livewire\Livewire::test(\App\Livewire\Customer\Auth\PasswordMigration::class, ['identifier' => $legacy->phone])
            ->assertOk()
            ->assertSet('step', 'verify_phone');
    }

    /**
     * `/auth/google` (customer.auth.google) is the second leg of "Continue
     * with Google": it requires a server-verified identity stashed in the
     * session by Login::continueWithGoogle(). With a fresh unlinked identity
     * it renders the mobile-verification step; bare, it bounces to login.
     */
    public function test_google_second_leg_renders_with_a_verified_session_identity(): void
    {
        $this->withSession(['auth.google' => [
            'uid' => 'guid-unseen-'.uniqid(),
            'email' => '',
            'name' => 'New Person',
            'verified_at' => now()->timestamp,
        ]])->get(route('customer.auth.google'))->assertOk();
    }

    public function test_google_second_leg_bounces_a_bare_guest_to_login(): void
    {
        $this->get(route('customer.auth.google'))
            ->assertRedirect(route('customer.login'));
    }

    // ─────────────────── Already-authenticated redirects ─────────────────

    public static function guestOnlyAuthRoutes(): array
    {
        return [
            ['customer.login'],
            ['customer.signup'],
            ['customer.password.forgot'],
            ['customer.auth.migrate'],
            ['customer.auth.google'],
        ];
    }

    #[DataProvider('guestOnlyAuthRoutes')]
    public function test_an_authenticated_customer_is_redirected_off_every_auth_page(string $routeName): void
    {
        $this->actingAs($this->makeCustomer())
            ->get(route($routeName))
            ->assertRedirect(route('customer.home'));
    }

    // ──────────────── Static reconciliation guard (the real net) ─────────

    /**
     * Every `route('customer.*')` / `redirectRoute('customer.*')` /
     * `to_route('customer.*')` name referenced anywhere in the customer web
     * views or Livewire components must be a registered route.
     *
     * This is the invariant production violated: login.blade.php referenced
     * `customer.password.forgot` against a route table that didn't define it.
     * A pure string scan catches it with no cache, no browser, no DB — and
     * regardless of whether the deployed route table is cached or fresh.
     */
    public function test_no_customer_view_references_an_unregistered_route_name(): void
    {
        $roots = [
            base_path('resources/views'),
            base_path('app/Livewire/Customer'),
            base_path('app/Http/Controllers/Customer'),
            base_path('app/Services/Customer'),
        ];

        $referenced = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! $file->isFile() || ! preg_match('/\.(php|blade\.php)$/', $file->getFilename())) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());

                if (preg_match_all(
                    "/(?:route|redirectRoute|to_route|redirectToRoute)\(\s*['\"](customer\.[a-zA-Z0-9._-]+)['\"]/",
                    $contents,
                    $matches
                )) {
                    foreach ($matches[1] as $name) {
                        $referenced[$name] ??= $this->relative($file->getPathname());
                    }
                }
            }
        }

        $this->assertNotEmpty($referenced, 'Scan found no customer route references — the pattern is probably broken.');

        $missing = [];
        foreach ($referenced as $name => $firstSeenIn) {
            if (! Route::has($name)) {
                $missing[] = "{$name}  (first referenced in {$firstSeenIn})";
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Customer views reference route names that are not registered in routes/web.php:\n  - "
                .implode("\n  - ", $missing)
        );
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), '/\\');
    }
}
