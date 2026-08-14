<?php

namespace Tests\Feature\Rbac;

use App\Livewire\Bookings\Index as BookingsIndex;
use App\Livewire\Bookings\Show as BookingsShow;
use App\Livewire\Commissions\Index as CommissionsIndex;
use App\Livewire\Customers\Index as CustomersIndex;
use App\Livewire\Customers\Show as CustomersShow;
use App\Livewire\Dashboard;
use App\Livewire\Loyalty\Index as LoyaltyIndex;
use App\Livewire\NotificationCenter\Manage as NotificationCenterManage;
use App\Livewire\Plans\Manage as PlansManage;
use App\Livewire\Providers\Index as ProvidersIndex;
use App\Livewire\Providers\Show as ProvidersShow;
use App\Livewire\Subscriptions\Index as SubscriptionsIndex;
use App\Livewire\WalletLedger\Index as WalletLedgerIndex;
use App\Livewire\Workers\Index as WorkersIndex;
use App\Livewire\Workers\Show as WorkersShow;
use App\Models\FieldWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Regression coverage for a real, systemic gap found this session (see
 * CURRENT_MASTER_CHECKPOINT.md's mission continuation): 12 `.view`
 * permissions were seeded across prior sessions (dashboard.view,
 * bookings.view, providers.view, workers.view, commissions.view,
 * wallets.view, loyalty.view, customers.view, subscriptions.view,
 * plans.view, notification.view — each with an explicit "this screen should
 * check it" comment in its own seeding migration) but NONE of them were
 * ever actually checked by the screen they were seeded for. Every mutating
 * action across the whole admin panel was properly gated (confirmed by a
 * full sweep of every hasPermission() call site in app/Livewire this
 * session) — only VIEWING was wide open to any actor who merely cleared
 * EnsureHasAdminAccess, exposing full cross-franchise commission splits,
 * wallet ledgers, loyalty/referral data, and customer PII to, e.g., a
 * Support-only actor holding nothing but banners.manage.
 *
 * Fixed by adding a mount()-level hasPermissionAnywhere() gate to each
 * affected screen — "anywhere", not a specific scope, matching this
 * codebase's existing precedent (AuthorizationService::canAnywhere(), used
 * by Bookings\Index::createCustomer() for the same "prerequisite gate, not
 * full row-scoping" reason) since none of these screens filter their
 * results by the viewer's own scope yet — that per-row scoping is a
 * separate, larger enhancement, not invented here.
 */
class ScreenViewAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    private function makeWorkerIn($franchise, $zone): FieldWorker
    {
        $user = \App\Models\User::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Worker',
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id,
        ]);

        return FieldWorker::create([
            'user_id' => $user->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'kyc_status' => 'approved', 'is_active' => true,
        ]);
    }

    /** @return array{denied: \Closure, allowed: \Closure} */
    private function screens(): array
    {
        $scenario = $this->makeBookingScenario();
        $worker = $this->makeWorkerIn($scenario['franchise'], $scenario['zone']);

        return [
            'Dashboard' => ['dashboard.view', fn ($actor) => Livewire::actingAs($actor)->test(Dashboard::class)],
            'Bookings\\Index' => ['bookings.view', fn ($actor) => Livewire::actingAs($actor)->test(BookingsIndex::class)],
            'Bookings\\Show' => ['bookings.view', fn ($actor) => Livewire::actingAs($actor)->test(BookingsShow::class, ['bookingId' => $scenario['booking']->id])],
            'Providers\\Index' => ['providers.view', fn ($actor) => Livewire::actingAs($actor)->test(ProvidersIndex::class)],
            'Providers\\Show' => ['providers.view', fn ($actor) => Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $scenario['provider']->id])],
            'Workers\\Index' => ['workers.view', fn ($actor) => Livewire::actingAs($actor)->test(WorkersIndex::class)],
            'Workers\\Show' => ['workers.view', fn ($actor) => Livewire::actingAs($actor)->test(WorkersShow::class, ['workerId' => $worker->id])],
            'Customers\\Index' => ['customers.view', fn ($actor) => Livewire::actingAs($actor)->test(CustomersIndex::class)],
            'Customers\\Show' => ['customers.view', fn ($actor) => Livewire::actingAs($actor)->test(CustomersShow::class, ['customerId' => $scenario['customer']->id])],
            'Commissions\\Index' => ['commissions.view', fn ($actor) => Livewire::actingAs($actor)->test(CommissionsIndex::class)],
            'WalletLedger\\Index' => ['wallets.view', fn ($actor) => Livewire::actingAs($actor)->test(WalletLedgerIndex::class)],
            'Loyalty\\Index' => ['loyalty.view', fn ($actor) => Livewire::actingAs($actor)->test(LoyaltyIndex::class)],
            'Subscriptions\\Index' => ['subscriptions.view', fn ($actor) => Livewire::actingAs($actor)->test(SubscriptionsIndex::class)],
            'Plans\\Manage' => ['plans.view', fn ($actor) => Livewire::actingAs($actor)->test(PlansManage::class)],
            'NotificationCenter\\Manage' => ['notification.view', fn ($actor) => Livewire::actingAs($actor)->test(NotificationCenterManage::class)],
        ];
    }

    /**
     * abort_unless()'s HttpException never reaches PHPUnit as a thrown
     * exception -- Livewire's own testing harness catches HttpExceptionInterface
     * internally (confirmed by reading Livewire's own
     * TestableLivewireCanAssertStatusCodesUnitTest) and exposes it via
     * assertStatus()/assertForbidden() on the returned Testable instead, the
     * same way a real HTTP request's exception handler would render an
     * error response rather than crash the process. Every assertion below
     * uses that real Livewire API rather than a try/catch that would never
     * actually catch anything.
     */
    public function test_every_gated_screen_denies_an_actor_without_its_view_permission(): void
    {
        foreach ($this->screens() as $label => [$permission, $render]) {
            $actor = $this->makeUserWithNoPermissions();

            $render($actor)->assertForbidden();
        }
    }

    public function test_every_gated_screen_allows_an_actor_holding_its_view_permission_anywhere(): void
    {
        foreach ($this->screens() as $label => [$permission, $render]) {
            $actor = $this->makeUserWithPermission($permission, 'global');

            $render($actor)->assertOk();
        }
    }

    public function test_every_gated_screen_allows_super_admin_regardless_of_role_assignments(): void
    {
        $admin = $this->makeSuperAdmin();

        foreach ($this->screens() as $label => [$permission, $render]) {
            $render($admin)->assertOk();
        }
    }
}
