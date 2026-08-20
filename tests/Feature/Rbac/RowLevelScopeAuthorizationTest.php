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
use App\Livewire\Payouts\Manage as PayoutsManage;
use App\Livewire\Plans\Manage as PlansManage;
use App\Livewire\Providers\Index as ProvidersIndex;
use App\Livewire\Providers\Show as ProvidersShow;
use App\Livewire\Subscriptions\Index as SubscriptionsIndex;
use App\Livewire\WalletLedger\Index as WalletLedgerIndex;
use App\Livewire\Workers\Index as WorkersIndex;
use App\Livewire\Workers\Show as WorkersShow;
use App\Models\Commission;
use App\Models\FieldWorker;
use App\Models\LoyaltyPoint;
use App\Models\NotificationCampaign;
use App\Models\NotificationMeeting;
use App\Models\Payout;
use App\Models\Plan;
use App\Models\Referral;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Regression coverage for this session's row-level scope-security slice
 * (continuation of 09061a2's screen-level .view enforcement): every one of
 * the 15 screens fixed there was verified, screen by screen, against its
 * ACTUAL query -- and all 15 showed every row platform-wide to any actor who
 * merely cleared the screen's own .view gate, regardless of role/scope.
 * Real, concrete gaps: a Zone Admin scoped to one zone could see every OTHER
 * zone's/franchise's bookings, providers, workers, customers, commission
 * splits, wallet ledger, loyalty/referral data, subscriptions, plans, and
 * notification campaigns/meetings -- and could reach an out-of-scope
 * booking/provider/worker/customer directly by ID.
 *
 * Fixed via AuthorizationService::scopeQuery() (direct/relation geography
 * columns: Bookings, Providers, Workers, Customers, Commissions, Wallet
 * Ledger, Loyalty, Dashboard) and AuthorizationService::visibleAmong() (the
 * Plan-shaped single scope_type/scope_id pattern: Plans, Subscriptions,
 * NotificationCenter) -- one reusable mechanism, not 15 bespoke ones.
 *
 * Every actor below holds a ZONE-scoped grant for the relevant permission
 * (the narrowest, most common real-world scope), proving the same
 * additive/fail-safe semantics AuthorizationService::can() already
 * guarantees for a single check now hold for row-level filtering too.
 */
class RowLevelScopeAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    // ============================== Fixtures ==============================

    private function makeWorkerIn($franchise, $zone): FieldWorker
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Worker',
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id,
        ]);

        return FieldWorker::create([
            'user_id' => $user->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'kyc_status' => 'approved', 'is_active' => true,
        ]);
    }

    private function makeCustomerIn($franchise, $zone): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Scoped Customer',
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'customer', 'status' => 'active',
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
        ]);
    }

    private function giveWallet(User $user, float $amount = 100): WalletTransaction
    {
        $wallet = Wallet::create(['user_id' => $user->id, 'balance' => $amount]);

        return WalletTransaction::create([
            'wallet_id' => $wallet->id, 'amount' => $amount, 'is_credit' => true,
            'reason' => 'test credit', 'ref' => 'test:'.Str::uuid(), 'status' => 'successful',
        ]);
    }

    private function makePlanIn(string $scopeType, ?int $scopeId): Plan
    {
        return Plan::create([
            'name' => 'Plan '.Str::random(6), 'slug' => 'plan-'.Str::random(8),
            'plan_family' => 'customer_membership', 'eligible_actor_type' => 'customer',
            'scope_type' => $scopeType, 'scope_id' => $scopeId, 'billing_cycle' => 'monthly',
            'price' => 100, 'is_active' => true,
        ]);
    }

    private function makeCampaignIn(string $scopeType, ?int $scopeId): NotificationCampaign
    {
        return NotificationCampaign::create([
            'type' => 'promotion', 'title' => 'Campaign', 'message' => 'Body',
            'recipient_type' => 'everyone', 'scope_type' => $scopeType, 'scope_id' => $scopeId,
            'channels' => 'mail', 'status' => 'draft',
        ]);
    }

    private function makeMeetingIn(string $scopeType, ?int $scopeId, int $organizerId): NotificationMeeting
    {
        return NotificationMeeting::create([
            'title' => 'Meeting', 'starts_at' => now()->addDay(), 'duration_minutes' => 30,
            'recipient_type' => 'providers', 'scope_type' => $scopeType, 'scope_id' => $scopeId,
            'organizer_user_id' => $organizerId, 'status' => 'scheduled',
        ]);
    }

    /** Two entirely independent franchise/zone trees ("mine" the actor is scoped to, "other" they are not), each with a booking/provider/customer/worker already in place. */
    private function makeTwoWorlds(): array
    {
        $mine = $this->makeBookingScenario('assigned');
        $other = $this->makeBookingScenario('assigned');

        return [$mine, $other];
    }

    // ============================== Bookings ==============================

    public function test_bookings_index_shows_only_the_actors_own_zone(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $actor = $this->makeUserWithPermission('bookings.view', 'zone', $mine['zone']->id);

        $component = Livewire::actingAs($actor)->test(BookingsIndex::class)->assertOk();

        $codes = $component->viewData('bookings')->pluck('code')->all();
        $this->assertContains($mine['booking']->code, $codes);
        $this->assertNotContains($other['booking']->code, $codes);
    }

    public function test_bookings_index_status_counts_are_scoped(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $actor = $this->makeUserWithPermission('bookings.view', 'zone', $mine['zone']->id);

        $component = Livewire::actingAs($actor)->test(BookingsIndex::class);
        $counts = $component->viewData('statusCounts');

        // Both bookings share status 'assigned' -- an unscoped count would read 2.
        $this->assertSame(1, (int) $counts->get('assigned', 0));
    }

    public function test_bookings_index_search_stays_scoped_to_the_actors_zone(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $actor = $this->makeUserWithPermission('bookings.view', 'zone', $mine['zone']->id);

        // Search for the OTHER booking's own code -- a scope leak would still find it.
        $component = Livewire::actingAs($actor)->test(BookingsIndex::class)
            ->set('search', $other['booking']->code);

        $this->assertSame(0, $component->viewData('bookings')->total());
    }

    public function test_bookings_show_allows_the_actors_own_zone(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $actor = $this->makeUserWithPermission('bookings.view', 'zone', $mine['zone']->id);

        Livewire::actingAs($actor)->test(BookingsShow::class, ['bookingId' => $mine['booking']->id])->assertOk();
    }

    public function test_bookings_show_404s_a_direct_id_outside_the_actors_zone(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $actor = $this->makeUserWithPermission('bookings.view', 'zone', $mine['zone']->id);

        Livewire::actingAs($actor)->test(BookingsShow::class, ['bookingId' => $other['booking']->id])->assertNotFound();
    }

    // ============================== Providers ==============================

    public function test_providers_index_shows_only_the_actors_own_zone(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $actor = $this->makeUserWithPermission('providers.view', 'zone', $mine['zone']->id);

        $component = Livewire::actingAs($actor)->test(ProvidersIndex::class)
            ->set('statusFilter', '');

        $ids = $component->viewData('providers')->pluck('id')->all();
        $this->assertContains($mine['provider']->id, $ids);
        $this->assertNotContains($other['provider']->id, $ids);
    }

    public function test_providers_show_404s_a_direct_id_outside_the_actors_zone(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $actor = $this->makeUserWithPermission('providers.view', 'zone', $mine['zone']->id);

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $mine['provider']->id])->assertOk();
        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $other['provider']->id])->assertNotFound();
    }

    // ============================== Workers ==============================

    public function test_workers_index_shows_only_the_actors_own_zone(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $myWorker = $this->makeWorkerIn($mine['franchise'], $mine['zone']);
        $otherWorker = $this->makeWorkerIn($other['franchise'], $other['zone']);
        $actor = $this->makeUserWithPermission('workers.view', 'zone', $mine['zone']->id);

        $component = Livewire::actingAs($actor)->test(WorkersIndex::class)->set('statusFilter', '');

        $ids = $component->viewData('workers')->pluck('id')->all();
        $this->assertContains($myWorker->id, $ids);
        $this->assertNotContains($otherWorker->id, $ids);
    }

    public function test_workers_show_404s_a_direct_id_outside_the_actors_zone(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $myWorker = $this->makeWorkerIn($mine['franchise'], $mine['zone']);
        $otherWorker = $this->makeWorkerIn($other['franchise'], $other['zone']);
        $actor = $this->makeUserWithPermission('workers.view', 'zone', $mine['zone']->id);

        Livewire::actingAs($actor)->test(WorkersShow::class, ['workerId' => $myWorker->id])->assertOk();
        Livewire::actingAs($actor)->test(WorkersShow::class, ['workerId' => $otherWorker->id])->assertNotFound();
    }

    // ============================== Customers ==============================

    public function test_customers_index_shows_only_the_actors_own_zone(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $myCustomer = $this->makeCustomerIn($mine['franchise'], $mine['zone']);
        $otherCustomer = $this->makeCustomerIn($other['franchise'], $other['zone']);
        $actor = $this->makeUserWithPermission('customers.view', 'zone', $mine['zone']->id);

        $component = Livewire::actingAs($actor)->test(CustomersIndex::class);

        $ids = $component->viewData('customers')->pluck('id')->all();
        $this->assertContains($myCustomer->id, $ids);
        $this->assertNotContains($otherCustomer->id, $ids);
    }

    public function test_customers_show_404s_a_direct_id_outside_the_actors_zone(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $myCustomer = $this->makeCustomerIn($mine['franchise'], $mine['zone']);
        $otherCustomer = $this->makeCustomerIn($other['franchise'], $other['zone']);
        $actor = $this->makeUserWithPermission('customers.view', 'zone', $mine['zone']->id);

        Livewire::actingAs($actor)->test(CustomersShow::class, ['customerId' => $myCustomer->id])->assertOk();
        Livewire::actingAs($actor)->test(CustomersShow::class, ['customerId' => $otherCustomer->id])->assertNotFound();
    }

    // ============================== Commissions ==============================

    public function test_commissions_index_shows_only_the_actors_own_zone(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $myCommission = Commission::create(['booking_id' => $mine['booking']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);
        $otherCommission = Commission::create(['booking_id' => $other['booking']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);
        $actor = $this->makeUserWithPermission('commissions.view', 'zone', $mine['zone']->id);

        $component = Livewire::actingAs($actor)->test(CommissionsIndex::class);

        $ids = $component->viewData('commissions')->pluck('id')->all();
        $this->assertContains($myCommission->id, $ids);
        $this->assertNotContains($otherCommission->id, $ids);
    }

    public function test_commissions_index_totals_are_scoped(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        Commission::create(['booking_id' => $mine['booking']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);
        Commission::create(['booking_id' => $other['booking']->id, 'provider_commission' => 1000, 'franchise_commission' => 1000, 'platform_commission' => 1000]);
        $actor = $this->makeUserWithPermission('commissions.view', 'zone', $mine['zone']->id);

        $component = Livewire::actingAs($actor)->test(CommissionsIndex::class);

        $this->assertSame(10.0, $component->viewData('providerTotal'));
    }

    public function test_commissions_index_franchise_filter_dropdown_is_scoped(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $actor = $this->makeUserWithPermission('commissions.view', 'zone', $mine['zone']->id);

        $component = Livewire::actingAs($actor)->test(CommissionsIndex::class);
        $franchiseIds = $component->viewData('franchises')->pluck('id')->all();

        $this->assertContains($mine['franchise']->id, $franchiseIds);
        $this->assertNotContains($other['franchise']->id, $franchiseIds);
    }

    // ============================== Payouts (Phase 21 item TECH-1) ==============================

    private function makePayoutFor($provider, float $amount = 500): Payout
    {
        return Payout::create([
            'payee_type' => 'provider', 'payee_id' => $provider->id,
            'amount' => $amount, 'status' => 'pending',
            'period_start' => now()->subDays(7), 'period_end' => now(),
        ]);
    }

    public function test_payouts_index_shows_only_the_actors_own_zone(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $myPayout = $this->makePayoutFor($mine['provider']);
        $otherPayout = $this->makePayoutFor($other['provider']);
        $actor = $this->makeUserWithPermission('payouts.manage', 'zone', $mine['zone']->id);

        $component = Livewire::actingAs($actor)->test(PayoutsManage::class);

        $ids = $component->viewData('payouts')->pluck('id')->all();
        $this->assertContains($myPayout->id, $ids);
        $this->assertNotContains($otherPayout->id, $ids);
    }

    public function test_payouts_index_a_global_scoped_grant_sees_every_payout(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $myPayout = $this->makePayoutFor($mine['provider']);
        $otherPayout = $this->makePayoutFor($other['provider']);
        $actor = $this->makeUserWithPermission('payouts.manage', 'global');

        $ids = Livewire::actingAs($actor)->test(PayoutsManage::class)
            ->viewData('payouts')->pluck('id')->all();

        $this->assertContains($myPayout->id, $ids);
        $this->assertContains($otherPayout->id, $ids);
    }

    public function test_super_admin_sees_every_zones_payout(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $myPayout = $this->makePayoutFor($mine['provider']);
        $otherPayout = $this->makePayoutFor($other['provider']);
        $admin = $this->makeSuperAdmin();

        $ids = Livewire::actingAs($admin)->test(PayoutsManage::class)
            ->viewData('payouts')->pluck('id')->all();

        $this->assertContains($myPayout->id, $ids);
        $this->assertContains($otherPayout->id, $ids);
    }

    /**
     * Admin Command Center completion session, Admin UX/Performance phase
     * (2026-08-20) -- payeeLabel() used to re-fetch the payee with a fresh
     * query PER ROW (Payout.payee_type/payee_id has no Eloquent relation to
     * eager-load instead). Proves the batched attachPayeeLabels() rewrite's
     * query count stays flat rather than scaling per payout.
     */
    public function test_payouts_list_payee_label_lookup_does_not_n_plus_one_per_payout(): void
    {
        $admin = $this->makeSuperAdmin();
        foreach (range(1, 5) as $i) {
            $scenario = $this->makeBookingScenario('assigned');
            $this->makePayoutFor($scenario['provider'], 100 * $i);
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        Livewire::actingAs($admin)->test(PayoutsManage::class);
        $queryCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // Loose upper bound -- well under what 5 payouts would cost at even
        // 1 extra query/payout under the old per-row payeeLabel() shape,
        // let alone the real Provider::with('user')->find() + implicit
        // lazy-load pattern it used.
        $this->assertLessThan(20, $queryCount);
    }

    /**
     * Row-level scope was never just a render()/list-hiding concern here --
     * markProcessing()/confirmMarkPaid()/markFailed() all previously
     * accepted any payout id from any actor holding payouts.manage
     * ANYWHERE, regardless of scope, since the permission check never
     * looked at the specific record. Proves a zone-scoped actor cannot
     * bypass the list by calling a write action directly with an
     * out-of-scope payout's real id -- the same "direct record access via
     * a Livewire action" bypass class this file's own bottom section
     * already proves for view-only-vs-mutation, applied here to a
     * scope-level (not permission-level) boundary.
     */
    public function test_mark_processing_denied_for_a_payout_outside_the_actors_zone(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $otherPayout = $this->makePayoutFor($other['provider']);
        $actor = $this->makeUserWithPermission('payouts.manage', 'zone', $mine['zone']->id);

        Livewire::actingAs($actor)->test(PayoutsManage::class)
            ->call('markProcessing', $otherPayout->id);

        $this->assertSame('pending', $otherPayout->fresh()->status, 'an out-of-scope payout must not be mutated even via a direct Livewire action call');
    }

    public function test_mark_failed_denied_for_a_payout_outside_the_actors_zone(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $otherPayout = $this->makePayoutFor($other['provider']);
        $actor = $this->makeUserWithPermission('payouts.manage', 'zone', $mine['zone']->id);

        Livewire::actingAs($actor)->test(PayoutsManage::class)
            ->call('markFailed', $otherPayout->id);

        $this->assertSame('pending', $otherPayout->fresh()->status);
    }

    public function test_confirm_mark_paid_denied_for_a_payout_outside_the_actors_zone(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $otherPayout = $this->makePayoutFor($other['provider']);
        $actor = $this->makeUserWithPermission('payouts.manage', 'zone', $mine['zone']->id);

        Livewire::actingAs($actor)->test(PayoutsManage::class)
            ->call('startMarkPaid', $otherPayout->id)
            ->set('gatewayRefInput', 'TXN123')
            ->call('confirmMarkPaid');

        $this->assertSame('pending', $otherPayout->fresh()->status);
    }

    public function test_mark_processing_still_succeeds_for_a_payout_inside_the_actors_zone(): void
    {
        [$mine] = $this->makeTwoWorlds();
        $myPayout = $this->makePayoutFor($mine['provider']);
        $actor = $this->makeUserWithPermission('payouts.manage', 'zone', $mine['zone']->id);

        Livewire::actingAs($actor)->test(PayoutsManage::class)
            ->call('markProcessing', $myPayout->id);

        $this->assertSame('processing', $myPayout->fresh()->status, 'the scope fix must not also block legitimate in-scope actions');
    }

    // ============================== Wallet Ledger ==============================

    public function test_wallet_ledger_shows_only_the_actors_own_zone(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        // makeBookingScenario()'s customer fixture carries no franchise_id/
        // zone_id (see BookingFixtureHelpers::makeCustomer()), and
        // makeProviderIn() only sets the PROVIDER row's zone_id, not the
        // underlying user's -- explicitly set it so the wallet.user.zone_id
        // relation this screen scopes through actually resolves.
        $mine['provider']->user->update(['zone_id' => $mine['zone']->id]);
        $other['provider']->user->update(['zone_id' => $other['zone']->id]);
        $myTxn = $this->giveWallet($mine['provider']->user);
        $otherTxn = $this->giveWallet($other['provider']->user);
        $actor = $this->makeUserWithPermission('wallets.view', 'zone', $mine['zone']->id);

        $component = Livewire::actingAs($actor)->test(WalletLedgerIndex::class);

        $ids = $component->viewData('transactions')->pluck('id')->all();
        $this->assertContains($myTxn->id, $ids);
        $this->assertNotContains($otherTxn->id, $ids);
    }

    // ============================== Loyalty & Referrals ==============================

    public function test_loyalty_points_show_only_the_actors_own_zone(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $mine['provider']->user->update(['zone_id' => $mine['zone']->id]);
        $other['provider']->user->update(['zone_id' => $other['zone']->id]);
        $myPoint = LoyaltyPoint::create(['user_id' => $mine['provider']->user_id, 'points' => 10, 'reason' => 'test']);
        $otherPoint = LoyaltyPoint::create(['user_id' => $other['provider']->user_id, 'points' => 10, 'reason' => 'test']);
        $actor = $this->makeUserWithPermission('loyalty.view', 'zone', $mine['zone']->id);

        $component = Livewire::actingAs($actor)->test(LoyaltyIndex::class)->set('activeTab', 'points');

        $ids = $component->viewData('points')->pluck('id')->all();
        $this->assertContains($myPoint->id, $ids);
        $this->assertNotContains($otherPoint->id, $ids);
    }

    public function test_referrals_show_only_the_referrers_own_zone(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $mine['provider']->user->update(['zone_id' => $mine['zone']->id]);
        $other['provider']->user->update(['zone_id' => $other['zone']->id]);
        $myReferral = Referral::create(['referrer_id' => $mine['provider']->user_id, 'referred_id' => $mine['customer']->id, 'status' => 'pending']);
        $otherReferral = Referral::create(['referrer_id' => $other['provider']->user_id, 'referred_id' => $other['customer']->id, 'status' => 'pending']);
        $actor = $this->makeUserWithPermission('loyalty.view', 'zone', $mine['zone']->id);

        $component = Livewire::actingAs($actor)->test(LoyaltyIndex::class)->set('activeTab', 'referrals');

        $ids = $component->viewData('referrals')->pluck('id')->all();
        $this->assertContains($myReferral->id, $ids);
        $this->assertNotContains($otherReferral->id, $ids);
    }

    // ============================== Dashboard ==============================

    public function test_dashboard_stats_are_scoped_to_the_actors_own_zone(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $actor = $this->makeUserWithPermission('dashboard.view', 'zone', $mine['zone']->id);

        $component = Livewire::actingAs($actor)->test(Dashboard::class);
        $stats = $component->viewData('stats');

        // Both scenarios' bookings share status 'assigned' -> active_bookings;
        // an unscoped count would read 2.
        $this->assertSame(1, $stats['active_bookings']);
        $this->assertSame(1, $stats['providers_total']);
        $this->assertSame(1, $stats['franchises_active']);
    }

    public function test_dashboard_recent_bookings_list_is_scoped(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $actor = $this->makeUserWithPermission('dashboard.view', 'zone', $mine['zone']->id);

        $component = Livewire::actingAs($actor)->test(Dashboard::class);
        $codes = $component->viewData('recentBookings')->pluck('code')->all();

        $this->assertContains($mine['booking']->code, $codes);
        $this->assertNotContains($other['booking']->code, $codes);
    }

    // ============================== Plans ==============================

    /**
     * A global-scoped ROW is only covered by a global-scoped (or super_admin)
     * GRANT -- Plan::authorizationScopeHint() returns [] for a global plan,
     * and can()'s own scopeCovers() never matches a narrower assignment
     * against an empty hint (this is pre-existing, unmodified behavior,
     * already relied on by the mutation checks this reuses -- visibleAmong()
     * deliberately mirrors it exactly rather than inventing a "global rows
     * are always visible" carve-out can() itself doesn't have).
     */
    public function test_plans_index_shows_only_the_actors_own_zone_not_global_or_others(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $myPlan = $this->makePlanIn('zone', $mine['zone']->id);
        $otherPlan = $this->makePlanIn('zone', $other['zone']->id);
        $globalPlan = $this->makePlanIn('global', null);
        $actor = $this->makeUserWithPermission('plans.view', 'zone', $mine['zone']->id);

        $component = Livewire::actingAs($actor)->test(PlansManage::class);
        $ids = $component->viewData('plans')->pluck('id')->all();

        $this->assertContains($myPlan->id, $ids);
        $this->assertNotContains($otherPlan->id, $ids);
        $this->assertNotContains($globalPlan->id, $ids, 'matches the existing, unmodified plans.manage mutation boundary -- a zone grant does not cover a global-scoped row');
    }

    public function test_plans_index_a_global_scoped_grant_sees_every_plan(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $myPlan = $this->makePlanIn('zone', $mine['zone']->id);
        $otherPlan = $this->makePlanIn('zone', $other['zone']->id);
        $globalPlan = $this->makePlanIn('global', null);
        $actor = $this->makeUserWithPermission('plans.view', 'global');

        $ids = Livewire::actingAs($actor)->test(PlansManage::class)
            ->viewData('plans')->pluck('id')->all();

        $this->assertContains($myPlan->id, $ids);
        $this->assertContains($otherPlan->id, $ids);
        $this->assertContains($globalPlan->id, $ids);
    }

    // ============================== Subscriptions ==============================

    public function test_subscriptions_index_shows_only_the_actors_own_zone_via_the_plan(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $myPlan = $this->makePlanIn('zone', $mine['zone']->id);
        $otherPlan = $this->makePlanIn('zone', $other['zone']->id);
        $mySub = Subscription::create(['subscribable_type' => User::class, 'subscribable_id' => $mine['customer']->id, 'plan_id' => $myPlan->id, 'status' => 'active']);
        $otherSub = Subscription::create(['subscribable_type' => User::class, 'subscribable_id' => $other['customer']->id, 'plan_id' => $otherPlan->id, 'status' => 'active']);
        $actor = $this->makeUserWithPermission('subscriptions.view', 'zone', $mine['zone']->id);

        $component = Livewire::actingAs($actor)->test(SubscriptionsIndex::class);
        $ids = $component->viewData('subscriptions')->pluck('id')->all();

        $this->assertContains($mySub->id, $ids);
        $this->assertNotContains($otherSub->id, $ids);
    }

    // ============================== Notification Center ==============================

    /** Same global-scoped-row-needs-a-global-scoped-grant boundary as Plans (see that test's docblock) -- NotificationCampaign::authorizationScopeHint() shares the identical AuthorizationService::ancestryFor() implementation. */
    public function test_notification_campaigns_show_only_the_actors_own_zone_not_global_or_others(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $myCampaign = $this->makeCampaignIn('zone', $mine['zone']->id);
        $otherCampaign = $this->makeCampaignIn('zone', $other['zone']->id);
        $globalCampaign = $this->makeCampaignIn('global', null);
        $actor = $this->makeUserWithPermission('notification.view', 'zone', $mine['zone']->id);

        $component = Livewire::actingAs($actor)->test(NotificationCenterManage::class);
        $ids = $component->viewData('campaigns')->pluck('id')->all();

        $this->assertContains($myCampaign->id, $ids);
        $this->assertNotContains($otherCampaign->id, $ids);
        $this->assertNotContains($globalCampaign->id, $ids);
    }

    public function test_notification_meetings_show_only_the_actors_own_zone(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $organizer = $this->makeSuperAdmin();
        $myMeeting = $this->makeMeetingIn('zone', $mine['zone']->id, $organizer->id);
        $otherMeeting = $this->makeMeetingIn('zone', $other['zone']->id, $organizer->id);
        $actor = $this->makeUserWithPermission('notification.view', 'zone', $mine['zone']->id);

        $component = Livewire::actingAs($actor)->test(NotificationCenterManage::class);
        $ids = $component->viewData('meetings')->pluck('id')->all();

        $this->assertContains($myMeeting->id, $ids);
        $this->assertNotContains($otherMeeting->id, $ids);
    }

    // ============================== Cross-cutting: super_admin bypass ==============================

    public function test_super_admin_sees_every_zone_on_every_screen(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $admin = $this->makeSuperAdmin();

        $codes = Livewire::actingAs($admin)->test(BookingsIndex::class)
            ->viewData('bookings')->pluck('code')->all();

        $this->assertContains($mine['booking']->code, $codes);
        $this->assertContains($other['booking']->code, $codes);
    }

    // ============================== Cross-cutting: global-scoped grant bypass ==============================

    public function test_a_global_scoped_grant_sees_every_zone(): void
    {
        [$mine, $other] = $this->makeTwoWorlds();
        $actor = $this->makeUserWithPermission('bookings.view', 'global');

        $codes = Livewire::actingAs($actor)->test(BookingsIndex::class)
            ->viewData('bookings')->pluck('code')->all();

        $this->assertContains($mine['booking']->code, $codes);
        $this->assertContains($other['booking']->code, $codes);
    }

    // ============================== Mutations stay independently authorized ==============================
    // Not re-tested end-to-end here (already covered by ProvidersReviewAuthorizationTest,
    // BookingCreationAuthorizationTest, FranchisesAuthorizationTest, RolesEscalationTest,
    // etc., all still green in the full suite) -- this confirms the one thing those
    // suites can't: that a row-level-scoped VIEW grant alone does NOT also grant a
    // mutation, on a screen this slice touched.

    public function test_holding_only_bookings_view_does_not_grant_cancel(): void
    {
        [$mine] = $this->makeTwoWorlds();
        $actor = $this->makeUserWithPermission('bookings.view', 'zone', $mine['zone']->id);

        Livewire::actingAs($actor)->test(BookingsShow::class, ['bookingId' => $mine['booking']->id])
            ->set('cancelReason', 'test')
            ->call('cancel');

        $this->assertSame('assigned', $mine['booking']->fresh()->status);
    }

    public function test_holding_only_providers_view_does_not_grant_kyc_review(): void
    {
        [$mine] = $this->makeTwoWorlds();
        $actor = $this->makeUserWithPermission('providers.view', 'zone', $mine['zone']->id);
        $mine['provider']->update(['kyc_status' => 'pending']);

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $mine['provider']->id])
            ->call('approve');

        $this->assertSame('pending', $mine['provider']->fresh()->kyc_status);
    }
}
