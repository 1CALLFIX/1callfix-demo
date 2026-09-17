<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Drawer\Utils;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-LAUNCH-010 — proves EnsureAccountNotSuspended now actually
 * re-runs on a Livewire component's SUBSEQUENT requests (wire:click,
 * property updates, etc.), not just its initial page load. LAUNCH-009
 * traced this exact gap to Livewire's own PersistentMiddleware mechanism
 * (vendor/livewire/livewire/src/Mechanisms/PersistentMiddleware/
 * PersistentMiddleware.php): its hardcoded allowlist decides which route
 * middleware replays on a component's update requests, and
 * EnsureAccountNotSuspended was never in it. Fixed in
 * AppServiceProvider::boot() via Livewire's own documented
 * addPersistentMiddleware() API — nothing here re-implements any part of
 * the suspension rule itself, and EnsureAccountNotSuspended's own logic is
 * untouched.
 *
 * Livewire::test() deliberately does NOT exercise this mechanism — its own
 * source (PersistentMiddleware::boot()) explicitly skips "any fake
 * requests such as a test", and internally mounts against a throwaway
 * `/livewire-unit-test-endpoint/...` route rather than the component's real
 * one, so a snapshot captured that way can never carry the real page's
 * path/method. Instead, these tests load the component's REAL, already-
 * registered production route via a genuine `$this->get()` (full real
 * middleware stack, exactly the "page already open" moment), then extract
 * the actual `wire:snapshot` Livewire embedded in that response HTML using
 * Livewire's own extraction utility (Drawer\Utils::
 * extractAttributeDataFromHtml) — the same one Livewire's own
 * SupportTesting\InitialRender uses internally. That snapshot's checksum is
 * valid for its real path from the start, so it can be replayed through a
 * genuine subsequent POST to Livewire's update endpoint, exactly
 * reproducing a real browser's wire:click round-trip.
 */
class LivewirePersistentSuspensionMiddlewareTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    /** The real page's own wire:snapshot, extracted from a genuine full-page GET. */
    private function realSnapshotFor(string $routeUri): array
    {
        $html = $this->get($routeUri)->getContent();

        return Utils::extractAttributeDataFromHtml($html, 'wire:snapshot');
    }

    /**
     * An empty `calls` array is deliberate and sufficient: PersistentMiddleware
     * runs BEFORE any component method dispatch, so if the HTTP response is
     * 403 here, no wire:click-invoked method could ever have run either —
     * this isolates and proves the middleware layer itself, independent of
     * which specific action a provider/customer would have clicked.
     */
    private function postUpdate(array $snapshot, array $updates = [])
    {
        return $this->withHeaders(['X-Livewire' => 'true'])->postJson(
            EndpointResolver::updatePath(),
            ['components' => [[
                'snapshot' => json_encode($snapshot),
                'updates' => $updates,
                'calls' => [],
            ]]]
        );
    }

    /**
     * actingAs() caches the resolved user on the guard instance for the
     * rest of this PHPUnit process; production has no such cache (every
     * real request re-reads the session fresh). Re-asserting identity with
     * a freshly-queried instance after a status mutation accurately
     * simulates "the same already-logged-in session, now reflecting the
     * DB's current status" rather than a stale in-memory copy from before
     * the mutation.
     */
    private function refreshActingAs(User $user): void
    {
        $this->actingAs($user->fresh());
    }

    // ============================== Provider ==============================

    public function test_a_suspended_providers_livewire_action_is_rejected_without_a_fresh_page_load(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);

        $this->actingAs($provider->user);
        $snapshot = $this->realSnapshotFor('/provider/jobs');

        // Suspended AFTER the page was "already open" — the exact scenario.
        $provider->user->update(['status' => 'suspended']);
        $this->refreshActingAs($provider->user);

        $this->postUpdate($snapshot)->assertStatus(403);
    }

    public function test_an_active_providers_livewire_action_remains_allowed(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);

        $this->actingAs($provider->user);
        $snapshot = $this->realSnapshotFor('/provider/jobs');

        $this->postUpdate($snapshot)->assertOk();
    }

    // ============================== Customer ==============================

    public function test_a_suspended_customers_livewire_action_is_rejected_without_a_fresh_page_load(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer);
        $snapshot = $this->realSnapshotFor('/wallet');

        $customer->update(['status' => 'suspended']);
        $this->refreshActingAs($customer);

        $this->postUpdate($snapshot)->assertStatus(403);
    }

    public function test_an_active_customers_livewire_action_remains_allowed(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer);
        $snapshot = $this->realSnapshotFor('/wallet');

        $this->postUpdate($snapshot)->assertOk();
    }

    // ============================== Admin — scope boundary, not a fix ==============================

    /**
     * Documents the boundary honestly rather than silently expanding scope
     * to force a pass: admin/staff suspension enforcement lives in a
     * SEPARATE middleware (EnsureHasAdminAccess, its own inline check),
     * never EnsureAccountNotSuspended — confirmed directly against
     * routes/admin.php below. This task's scope names
     * EnsureAccountNotSuspended specifically and explicitly forbids
     * creating a second suspension middleware, so EnsureHasAdminAccess was
     * NOT registered as persistent here. A fresh admin page
     * load/navigation still correctly catches a suspended admin (already
     * proven by SuspendedAccountCannotLoginTest's existing middleware
     * test) — only the already-open-Livewire-tab case remains open for
     * admin, and is out of this task's named scope.
     */
    public function test_admin_suspension_enforcement_is_a_separate_middleware_not_covered_by_this_fix(): void
    {
        $this->assertStringNotContainsString(
            'EnsureAccountNotSuspended',
            file_get_contents(base_path('routes/admin.php')),
            'Admin routes use EnsureHasAdminAccess, not EnsureAccountNotSuspended -- if this ever changes, this test (and this task\'s scope note) needs revisiting.'
        );

        $admin = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Admin', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'email' => Str::random(10).'@example.test',
            'role' => 'super_admin', 'status' => 'active',
        ]);

        $this->actingAs($admin);
        $snapshot = $this->realSnapshotFor('/admin/dashboard');

        $admin->update(['status' => 'suspended']);
        $this->refreshActingAs($admin);

        // Confirms the CURRENT, expected-given-scope behavior: the request
        // still succeeds, because EnsureHasAdminAccess was never made
        // persistent (unlike EnsureAccountNotSuspended). Not a regression
        // introduced by this task -- this gap pre-dates it and remains a
        // separate, un-actioned follow-up.
        $this->postUpdate($snapshot)->assertOk();
    }

    // ============================== IDOR / client-manipulation ==============================

    /**
     * EnsureAccountNotSuspended::handle() reads exclusively
     * $request->user() -- the session's own resolved user, established at
     * login and never re-derived from the Livewire payload. Nothing in the
     * update request (snapshot, property updates, method calls) names a
     * user/provider id the middleware ever reads. Proven here by sending a
     * real, validly-checksummed component update (a harmless property
     * write) alongside the suspended session: the middleware still fires
     * and blocks it BEFORE the update is ever applied, regardless of what
     * the update payload itself contains.
     */
    public function test_client_supplied_payload_content_cannot_bypass_suspension(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer);
        $snapshot = $this->realSnapshotFor('/wallet');

        $customer->update(['status' => 'suspended']);
        $this->refreshActingAs($customer);

        // A real, otherwise-harmless property write -- proves the
        // middleware blocks the REQUEST, not just a specific call.
        $this->postUpdate($snapshot, ['topUpAmount' => '500'])->assertStatus(403);
    }
}
