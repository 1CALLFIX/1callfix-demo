<?php

namespace Tests\Feature\Rbac;

use App\Livewire\Banners\Manage as BannersManage;
use App\Livewire\Bookings\Index as BookingsIndex;
use App\Livewire\Bookings\Show as BookingsShow;
use App\Livewire\Categories\Manage as CategoriesManage;
use App\Livewire\Cms\Manage as CmsManage;
use App\Livewire\Commissions\Index as CommissionsIndex;
use App\Livewire\Customers\Index as CustomersIndex;
use App\Livewire\Customers\Show as CustomersShow;
use App\Livewire\Dashboard;
use App\Livewire\Franchises\Manage as FranchisesManage;
use App\Livewire\FranchisePricing\Manage as FranchisePricingManage;
use App\Livewire\Geography\Manage as GeographyManage;
use App\Livewire\Loyalty\Index as LoyaltyIndex;
use App\Livewire\NotificationCenter\Manage as NotificationCenterManage;
use App\Livewire\Payouts\Manage as PayoutsManage;
use App\Livewire\Plans\Manage as PlansManage;
use App\Livewire\Providers\Index as ProvidersIndex;
use App\Livewire\Providers\Show as ProvidersShow;
use App\Livewire\Roles\Manage as RolesManage;
use App\Livewire\Services\Manage as ServicesManage;
use App\Livewire\Settings\Manage as SettingsManage;
use App\Livewire\Subcategories\Manage as SubcategoriesManage;
use App\Livewire\Subscriptions\Index as SubscriptionsIndex;
use App\Livewire\WalletLedger\Index as WalletLedgerIndex;
use App\Livewire\Workers\Index as WorkersIndex;
use App\Livewire\Workers\Show as WorkersShow;
use App\Livewire\Zones\Manage as ZonesManage;
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
 *
 * Extended (Phase 11 — Admin Menu/Settings completeness audit) with 12 MORE
 * screens found to have exactly the same gap: Banners, Categories, Cms,
 * Geography, Payouts, Roles, Subcategories, Franchises, FranchisePricing,
 * Services, Settings, Zones. These never even had a dedicated `.view`
 * permission seeded (only a `.manage` slug, already required by every
 * write action on each screen) — so, unlike the first batch above, each
 * reuses its own `.manage` slug as the view gate rather than inventing a
 * new permission concept.
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
            'Banners\\Manage' => ['banners.manage', fn ($actor) => Livewire::actingAs($actor)->test(BannersManage::class)],
            'Categories\\Manage' => ['categories.manage', fn ($actor) => Livewire::actingAs($actor)->test(CategoriesManage::class)],
            'Cms\\Manage' => ['cms.manage', fn ($actor) => Livewire::actingAs($actor)->test(CmsManage::class)],
            'Geography\\Manage' => ['geography.manage', fn ($actor) => Livewire::actingAs($actor)->test(GeographyManage::class)],
            'Payouts\\Manage' => ['payouts.manage', fn ($actor) => Livewire::actingAs($actor)->test(PayoutsManage::class)],
            'Roles\\Manage' => ['roles.manage', fn ($actor) => Livewire::actingAs($actor)->test(RolesManage::class)],
            'Subcategories\\Manage' => ['categories.manage', fn ($actor) => Livewire::actingAs($actor)->test(SubcategoriesManage::class)],
            'Franchises\\Manage' => ['franchises.manage', fn ($actor) => Livewire::actingAs($actor)->test(FranchisesManage::class)],
            'FranchisePricing\\Manage' => ['franchise_pricing.manage', fn ($actor) => Livewire::actingAs($actor)->test(FranchisePricingManage::class, ['franchiseId' => $scenario['franchise']->id])],
            'Services\\Manage' => ['services.manage', fn ($actor) => Livewire::actingAs($actor)->test(ServicesManage::class)],
            'Settings\\Manage' => ['settings.manage', fn ($actor) => Livewire::actingAs($actor)->test(SettingsManage::class)],
            'Zones\\Manage' => ['zones.manage', fn ($actor) => Livewire::actingAs($actor)->test(ZonesManage::class)],
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
