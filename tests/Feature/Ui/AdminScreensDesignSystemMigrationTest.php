<?php

namespace Tests\Feature\Ui;

use App\Livewire\Auth\Login as AuthLogin;
use App\Livewire\Badges\Manage as BadgesManage;
use App\Livewire\Bookings\Index as BookingsIndex;
use App\Livewire\Bookings\Show as BookingsShow;
use App\Livewire\Chat\Manage as ChatManage;
use App\Livewire\Cms\Manage as CmsManage;
use App\Livewire\Commissions\Index as CommissionsIndex;
use App\Livewire\Customers\Index as CustomersIndex;
use App\Livewire\Customers\Show as CustomersShow;
use App\Livewire\Dashboard;
use App\Livewire\FlashSales\Manage as FlashSalesManage;
use App\Livewire\FranchisePricing\Manage as FranchisePricingManage;
use App\Livewire\Geography\Manage as GeographyManage;
use App\Livewire\Kyc\SupportRequests as KycSupportRequests;
use App\Livewire\Loyalty\Index as LoyaltyIndex;
use App\Livewire\Operations\Health as OperationsHealth;
use App\Livewire\Payments\Index as PaymentsIndex;
use App\Livewire\Payouts\Manage as PayoutsManage;
use App\Livewire\PerformanceCampaigns\Manage as PerformanceCampaignsManage;
use App\Livewire\Plans\Manage as PlansManage;
use App\Livewire\Providers\Index as ProvidersIndex;
use App\Livewire\Providers\Show as ProvidersShow;
use App\Livewire\Roles\Manage as RolesManage;
use App\Livewire\Subscriptions\Index as SubscriptionsIndex;
use App\Livewire\WalletLedger\Index as WalletLedgerIndex;
use App\Livewire\Workers\Index as WorkersIndex;
use App\Livewire\Workers\Show as WorkersShow;
use App\Models\Badge;
use App\Models\ChatMessage;
use App\Models\Commission;
use App\Models\ContentPage;
use App\Models\Country;
use App\Models\EntitlementBalance;
use App\Models\Faq;
use App\Models\FlashSale;
use App\Models\LoyaltyPoint;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\PerformanceCampaign;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Referral;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase 21 item TECH-6 (Admin UI design system, first increment). Proves
 * the 7 screens migrated onto the new `x-ui.*` components (button, card,
 * table, badge) still render their real data correctly through the new
 * markup, AND that each screen's key interaction (a filter, a status
 * toggle, an export action) still actually fires the same Livewire method
 * it did before the migration -- not just that the page loads.
 *
 * This deliberately does NOT re-test authorization (ScreenViewAuthorizationTest,
 * RowLevelScopeAuthorizationTest) or currency/timezone display (Currency
 * SymbolDisplayTest, TimezoneDisplayTest) -- those suites already cover
 * these same 7 screens for their own concerns and continue to pass
 * unmodified against the migrated markup (see CURRENT_MASTER_CHECKPOINT.md's
 * Phase 21 TECH-6 entry for the full regression run). This suite is scoped
 * purely to "did the component migration preserve real behavior."
 */
class AdminScreensDesignSystemMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    // --- Providers\Index ---------------------------------------------------

    public function test_providers_index_renders_pending_provider_and_status_filter_switches_results(): void
    {
        $scenario = $this->makeBookingScenario();
        $scenario['provider']->update(['kyc_status' => 'pending']);
        // The default value ("Provider") is a substring of the page's own
        // "Providers" heading, which would make an assertDontSee() below a
        // false negative regardless of whether the row is really gone --
        // use a distinctive name instead, same reasoning
        // CurrencySymbolDisplayTest's own docblock gives for picking ¥.
        $scenario['provider']->user->update(['name' => 'Zzyx Applicant']);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(ProvidersIndex::class);
        $component->assertSee('Zzyx Applicant')
            ->assertSee('Review');

        // Key interaction: the status-filter button is a real wire:click
        // action, not a static tab -- switching it away from "pending"
        // (the default) actually removes the pending provider from view.
        $component->set('statusFilter', 'approved')
            ->assertDontSee('Zzyx Applicant');
    }

    // --- Workers\Index -------------------------------------------------------

    public function test_workers_index_renders_pending_worker_and_status_filter_switches_results(): void
    {
        $scenario = $this->makeBookingScenario();
        $worker = $this->makeFieldWorkerIn($scenario['franchise'], $scenario['zone']);
        $worker->update(['kyc_status' => 'pending']);
        // Same "Worker" is a substring of "Workers" (the page heading)
        // reasoning as ProvidersIndex's own test above -- use a distinctive name.
        $worker->user->update(['name' => 'Zzyx Applicant']);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(WorkersIndex::class);
        $component->assertSee('Zzyx Applicant')
            ->assertSee('Review');

        $component->set('statusFilter', 'approved')
            ->assertDontSee('Zzyx Applicant');
    }

    // --- Customers\Index -----------------------------------------------------

    public function test_customers_index_renders_status_badge_and_search_filters_results(): void
    {
        $active = $this->makeCustomer();
        $active->update(['name' => 'Findable Customer', 'status' => 'active']);
        $suspended = $this->makeCustomer();
        $suspended->update(['name' => 'Suspended Customer', 'status' => 'suspended']);

        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(CustomersIndex::class);
        $component->assertSee('Findable Customer')
            ->assertSee('Suspended Customer')
            ->assertSee('active')
            ->assertSee('suspended');

        // Key interaction: live search actually narrows the result set.
        $component->set('search', 'Findable')
            ->assertSee('Findable Customer')
            ->assertDontSee('Suspended Customer');
    }

    // --- Customers\Show ------------------------------------------------------

    public function test_customers_show_renders_details_card_and_suspend_button_toggles_status(): void
    {
        $customer = $this->makeCustomer();
        Wallet::create(['user_id' => $customer->id, 'balance' => 100]);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(CustomersShow::class, ['customerId' => $customer->id]);
        $component->assertSee($customer->phone)
            ->assertSee('active')
            ->assertSee('Suspend');

        // Key interaction: the x-ui.button wired to toggleSuspended() still
        // calls the real Livewire method and flips real DB state.
        $component->call('toggleSuspended');

        $this->assertSame('suspended', $customer->fresh()->status);
        $component->assertSee('suspended')->assertSee('Reactivate');
    }

    // --- Commissions\Index -----------------------------------------------

    public function test_commissions_index_renders_totals_table_and_export_button_is_wired(): void
    {
        $scenario = $this->makeBookingScenario('completed');
        Commission::create([
            'booking_id' => $scenario['booking']->id,
            'provider_commission' => 80, 'franchise_commission' => 10, 'platform_commission' => 10,
        ]);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(CommissionsIndex::class);
        $component->assertSee($scenario['booking']->code)
            ->assertSee('80.00', false);

        // Key interaction: the x-ui.button renders with the real
        // exportCommissions() Livewire action wired onto it (not just a
        // static label). The export MECHANISM itself (real xlsx content,
        // row-level scope, masked fields) is already covered directly
        // against CommissionsExport in DataExportTest.php -- deliberately
        // not re-run end-to-end through a real Excel::download() stream
        // here too, since generating a real xlsx binary on top of the
        // rest of a full-suite run is measurably memory-heavy for a check
        // that would only be re-proving markup wiring, not new behavior.
        $component->assertSeeHtml('wire:click="exportCommissions"');
    }

    // --- Wallet Ledger\Index ---------------------------------------------

    public function test_wallet_ledger_index_renders_credit_and_debit_badges_and_type_filter_switches_results(): void
    {
        $customer = $this->makeCustomer();
        $wallet = Wallet::create(['user_id' => $customer->id, 'balance' => 100]);
        WalletTransaction::create([
            'wallet_id' => $wallet->id, 'amount' => 50, 'is_credit' => true,
            'reason' => 'Top-up', 'ref' => 'ref-credit-'.uniqid(), 'status' => 'successful',
        ]);
        WalletTransaction::create([
            'wallet_id' => $wallet->id, 'amount' => 20, 'is_credit' => false,
            'reason' => 'Booking payment', 'ref' => 'ref-debit-'.uniqid(), 'status' => 'successful',
        ]);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(WalletLedgerIndex::class);
        $component->assertSee('Credit')->assertSee('Debit');

        // Key interaction: the type filter select narrows to only credits.
        $component->set('typeFilter', 'credit')
            ->assertSee('Top-up')
            ->assertDontSee('Booking payment');
    }

    // --- Payments\Index ------------------------------------------------------

    public function test_payments_index_renders_purpose_and_status_badges_and_purpose_filter_switches_results(): void
    {
        $customer = $this->makeCustomer();
        $topupOrderId = 'order_topup_'.uniqid();
        Payment::create([
            'purpose' => 'wallet_topup', 'user_id' => $customer->id, 'amount' => 500,
            'gateway' => 'razorpay', 'gateway_order_id' => $topupOrderId, 'status' => 'captured', 'captured_at' => now(),
        ]);
        $scenario = $this->makeBookingScenario('completed');
        Payment::create([
            'purpose' => 'booking', 'booking_id' => $scenario['booking']->id, 'amount' => 500,
            'gateway' => 'razorpay', 'gateway_order_id' => 'order_booking_'.uniqid(), 'status' => 'captured', 'captured_at' => now(),
        ]);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(PaymentsIndex::class);
        $component->assertSee($topupOrderId)
            ->assertSee('Captured');

        // Key interaction: the purpose filter narrows to only booking
        // payments. Asserted via the top-up's own distinctive gateway
        // order id, not the literal "Wallet top-up" label -- that label
        // also always appears inside the (never-hidden) purpose <select>
        // options regardless of which purpose is actually filtered.
        $component->set('purposeFilter', 'booking')
            ->assertSee($scenario['booking']->code)
            ->assertDontSee($topupOrderId);
    }

    // ========================================================================
    // Second increment (2026-08-16) -- 10 more screens: FranchisePricing\
    // Manage, Kyc\SupportRequests, Chat\Manage, Subscriptions\Index,
    // Dashboard, Geography\Manage, Workers\Show, Loyalty\Index, Providers\
    // Show, Payouts\Manage. Payouts\Manage is also the first real screen to
    // wire x-ui.modal (the "Mark Paid" transfer-reference confirmation,
    // previously an inline table-row input with no cancel affordance).
    // ========================================================================

    // --- FranchisePricing\Manage -----------------------------------------

    public function test_franchise_pricing_manage_renders_service_row_and_save_button_is_wired(): void
    {
        $scenario = $this->makeBookingScenario();
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(FranchisePricingManage::class, ['franchiseId' => $scenario['franchise']->id]);
        $component->assertSee($scenario['service']->name)
            ->assertSeeHtml('wire:click="saveRow('.$scenario['service']->id.')"');
    }

    // --- Kyc\SupportRequests -----------------------------------------------

    public function test_kyc_support_requests_renders_open_request_and_decide_actions_are_wired(): void
    {
        $scenario = $this->makeBookingScenario();
        $request = \App\Models\KycSupportRequest::create([
            'provider_id' => $scenario['provider']->id, 'franchise_id' => $scenario['franchise']->id,
            'raised_by' => $scenario['provider']->user_id, 'reason' => 'Needs urgent withdrawal',
            'urgency' => 'high', 'status' => 'open',
        ]);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(KycSupportRequests::class);
        $component->assertSee('Needs urgent withdrawal')
            ->assertSee('High urgency');

        // Key interaction: "Decide" is a real wire:click, and the three
        // decision buttons that appear afterward reach the real decide()
        // method with the real request id + outcome.
        $component->call('startDeciding', $request->id)
            ->assertSeeHtml('wire:click="decide('.$request->id.", 'approved')\"");
    }

    // --- Chat\Manage ---------------------------------------------------------

    public function test_chat_manage_renders_conversation_list_and_view_opens_the_real_thread(): void
    {
        $scenario = $this->makeBookingScenario('assigned');
        ChatMessage::create([
            'booking_id' => $scenario['booking']->id, 'sender_id' => $scenario['customer']->id,
            'receiver_id' => $scenario['provider']->user_id, 'message' => 'Distinctive chat text',
        ]);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(ChatManage::class);
        $component->assertSee($scenario['booking']->code);

        // Key interaction: selectBooking() is real, row-authorized, and
        // switches the same component into the thread view showing the
        // real message.
        $component->call('selectBooking', $scenario['booking']->id)
            ->assertSee('Distinctive chat text');
    }

    // --- Subscriptions\Index ------------------------------------------------

    public function test_subscriptions_index_renders_active_subscription_and_status_filter_switches_results(): void
    {
        $customer = $this->makeCustomer();
        $plan = Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-plan-'.uniqid(),
            'plan_family' => 'customer_membership', 'scope_type' => 'global',
            'eligible_actor_type' => 'customer', 'billing_cycle' => 'monthly',
            'price' => 199, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);
        $entitlement = PlanEntitlement::create([
            'plan_id' => $plan->id, 'entitlement_type' => 'monetary_allowance',
            'monetary_value' => 500, 'usage_period' => 'monthly',
            'consumption_trigger' => 'booking_created', 'rollover_policy' => 'none',
        ]);
        $subscription = Subscription::create([
            'subscribable_type' => User::class, 'subscribable_id' => $customer->id,
            'plan_id' => $plan->id, 'status' => 'active',
            'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);
        EntitlementBalance::create([
            'subscription_id' => $subscription->id, 'plan_entitlement_id' => $entitlement->id,
            'period_start' => now(), 'period_end' => now()->addMonth(),
            'granted_monetary_value' => 500, 'status' => 'current',
        ]);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(SubscriptionsIndex::class);
        $component->assertSee('active')
            ->assertSeeHtml('wire:click="pause('.$subscription->id.')"');

        // Key interaction: the status filter narrows the result set.
        $component->set('statusFilter', 'cancelled')
            ->assertDontSee($customer->name);
    }

    // --- Dashboard -------------------------------------------------------

    public function test_dashboard_renders_pipeline_and_period_switch_still_calls_setPeriod(): void
    {
        $scenario = $this->makeBookingScenario('completed');
        $scenario['booking']->update(['price_final' => 777]);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(Dashboard::class);
        $component->assertSee($scenario['booking']->code);

        // Key interaction: the period tab buttons are real wire:click
        // actions reaching the real setPeriod() method.
        $component->call('setPeriod', 'month');
        $this->assertSame('month', $component->get('period'));
    }

    // --- Geography\Manage ----------------------------------------------------

    public function test_geography_manage_renders_country_and_toggle_active_reaches_real_method(): void
    {
        $country = Country::create(['name' => 'Zzyx Country', 'code' => 'ZZ', 'currency_code' => 'ZZZ', 'default_timezone' => 'UTC', 'is_active' => true]);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(GeographyManage::class);
        $component->assertSee('Zzyx Country')
            ->assertSee('active');

        // Key interaction: the toggle button is a real wire:click reaching
        // toggleCountryActive(), which actually flips real DB state.
        $component->call('toggleCountryActive', $country->id);

        $this->assertFalse($country->fresh()->is_active);
        $component->assertSee('inactive');
    }

    // --- Workers\Show --------------------------------------------------------

    public function test_workers_show_renders_details_card_and_toggle_active_reaches_real_method(): void
    {
        $scenario = $this->makeBookingScenario();
        $worker = $this->makeFieldWorkerIn($scenario['franchise'], $scenario['zone']);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(WorkersShow::class, ['workerId' => $worker->id]);
        $component->assertSee($worker->user->phone)
            ->assertSee('Active');

        // Key interaction: the x-ui.button wired to toggleActive() still
        // calls the real Livewire method and flips real DB state.
        $component->call('toggleActive');

        $this->assertFalse($worker->fresh()->is_active);
        $component->assertSee('Inactive');
    }

    // --- Loyalty\Index -------------------------------------------------------

    public function test_loyalty_index_renders_points_tab_and_referrals_tab_flag_action_is_wired(): void
    {
        $scenario = $this->makeBookingScenario();
        LoyaltyPoint::create(['user_id' => $scenario['customer']->id, 'points' => 25, 'reason' => 'booking_completed']);
        $referred = $this->makeCustomer();
        $referral = Referral::create(['referrer_id' => $scenario['customer']->id, 'referred_id' => $referred->id, 'status' => 'pending']);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(LoyaltyIndex::class);
        $component->assertSee('25');

        // Key interaction: switching tabs is a real wire:click reaching
        // setTab(), and the "Flag as fraud" ghost button on the referrals
        // tab is wired to the real startFlagging() method.
        $component->call('setTab', 'referrals')
            ->assertSeeHtml('wire:click="startFlagging('.$referral->id.')"');
    }

    // --- Providers\Show ------------------------------------------------------

    public function test_providers_show_renders_details_card_and_priority_save_is_wired(): void
    {
        $scenario = $this->makeBookingScenario();
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(ProvidersShow::class, ['providerId' => $scenario['provider']->id]);
        $component->assertSee($scenario['provider']->user->phone)
            ->assertSeeHtml('wire:click="updatePriority"');
    }

    // --- Payouts\Manage (also the first real x-ui.modal wiring) -----------

    public function test_payouts_manage_renders_pending_payout_and_mark_paid_modal_reaches_the_same_methods(): void
    {
        $scenario = $this->makeBookingScenario();
        $payout = Payout::create([
            'payee_type' => 'provider', 'payee_id' => $scenario['provider']->id,
            'amount' => 500, 'status' => 'pending',
            'period_start' => now()->subDays(7), 'period_end' => now(),
        ]);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(PayoutsManage::class);
        $component->assertSee('500.00', false)
            ->assertSee('pending');

        // Key interaction: startMarkPaid() opens the same modal-driven
        // flow the inline input used to (identical method/property names
        // -- RowLevelScopeAuthorizationTest calls these directly and is
        // unaffected), and confirmMarkPaid() still moves the real payout
        // to 'paid' via PayoutService.
        $component->call('startMarkPaid', $payout->id)
            ->assertSet('markingPaidId', $payout->id)
            ->assertSeeHtml('wire:click="confirmMarkPaid"')
            ->set('gatewayRefInput', 'TXN-999')
            ->call('confirmMarkPaid');

        $this->assertSame('paid', $payout->fresh()->status);
        $component->assertSet('markingPaidId', null);
    }

    public function test_payouts_manage_mark_paid_modal_cancel_is_wired_and_resets_state(): void
    {
        $scenario = $this->makeBookingScenario();
        $payout = Payout::create([
            'payee_type' => 'provider', 'payee_id' => $scenario['provider']->id,
            'amount' => 500, 'status' => 'pending',
            'period_start' => now()->subDays(7), 'period_end' => now(),
        ]);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(PayoutsManage::class)
            ->call('startMarkPaid', $payout->id)
            ->assertSet('markingPaidId', $payout->id);

        // Key interaction: the modal's Cancel button and its backdrop/X
        // (both wired to the same $onClose="cancelMarkPaid") reach the new
        // cancelMarkPaid() method, closing the modal without mutating the
        // payout.
        $component->call('cancelMarkPaid')
            ->assertSet('markingPaidId', null);

        $this->assertSame('pending', $payout->fresh()->status);
    }

    // ========================================================================
    // Third increment (2026-08-16) -- 10 more screens: Auth\Login, Badges\
    // Manage, Bookings\Show, Roles\Manage, Cms\Manage, Plans\Manage,
    // Bookings\Index, Operations\Health, PerformanceCampaigns\Manage,
    // FlashSales\Manage. Cms\Manage is the second real screen (after
    // Payouts\Manage) to wire x-ui.modal -- all four of its own dialogs
    // (Edit Page, Edit FAQ, Delete Page, Delete FAQ) were already
    // hand-rolling nearly identical markup, a natural fit rather than a
    // forced conversion.
    // ========================================================================

    // --- Auth\Login ------------------------------------------------------

    public function test_login_renders_card_and_submit_still_authenticates(): void
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Login Test Admin',
            'email' => Str::random(10).'@example.test',
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'password' => Hash::make('correct-password'),
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $component = Livewire::test(AuthLogin::class);
        $component->assertSeeHtml('wire:submit="submit"');

        // Key interaction: the x-ui.button submit button still reaches the
        // real submit() method and a correct login still redirects into
        // the panel -- unchanged behavior through the new markup.
        $component->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('submit')
            ->assertRedirect(route('admin.dashboard'));
    }

    // --- Badges\Manage -----------------------------------------------------

    public function test_badges_manage_renders_definition_and_toggle_active_reaches_real_method(): void
    {
        $badge = Badge::create(['key' => 'zzyx-test-badge', 'label' => 'Zzyx Badge', 'mode' => 'manual', 'priority' => 1, 'text_color' => '#fff', 'bg_color' => '#000', 'is_active' => true]);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(BadgesManage::class);
        $component->assertSee('Zzyx Badge')
            ->assertSee('Active');

        // Key interaction: the x-ui.button wired to toggleBadgeActive()
        // still calls the real Livewire method and flips real DB state.
        $component->call('toggleBadgeActive', $badge->id);

        $this->assertFalse($badge->fresh()->is_active);
        $component->assertSee('Inactive');
    }

    // --- Bookings\Show -------------------------------------------------------

    public function test_bookings_show_renders_status_badge_and_cancel_reaches_real_method(): void
    {
        $scenario = $this->makeBookingScenario();
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(BookingsShow::class, ['bookingId' => $scenario['booking']->id]);
        $component->assertSee($scenario['booking']->code)
            ->assertSee('searching provider');

        // Key interaction: the x-ui.button wired to cancel() still calls
        // the real Livewire method and moves real DB state.
        $component->set('cancelReason', 'Customer requested cancellation')
            ->call('cancel');

        $this->assertSame('cancelled', $scenario['booking']->fresh()->status);
        $component->assertSee('cancelled');
    }

    // --- Roles\Manage --------------------------------------------------------

    public function test_roles_manage_renders_assignment_and_revoke_confirm_reaches_real_method(): void
    {
        $admin = $this->makeSuperAdmin();
        $permission = Permission::firstOrCreate(['slug' => 'zzyx.test'], ['label' => 'Zzyx Test', 'group' => 'Test']);
        $role = Role::create(['name' => 'Zzyx Role', 'slug' => 'zzyx-role-'.Str::random(6), 'description' => 'Test role', 'is_system' => false]);
        $role->permissions()->attach($permission->id);
        $target = $this->makeCustomer();
        $target->update(['name' => 'Zzyx Assignee']);
        $assignment = RoleAssignment::create(['user_id' => $target->id, 'role_id' => $role->id, 'scope_type' => 'global', 'scope_id' => null]);

        $component = Livewire::actingAs($admin)->test(RolesManage::class);
        $component->assertSee('Zzyx Assignee')
            ->assertSee('Zzyx Role');

        // Key interaction: confirmRevoke() (a real wire:click on the
        // x-ui.button) still reaches the real Livewire method, and the
        // real revoke() afterward still deletes the assignment.
        $component->call('confirmRevoke', $assignment->id)
            ->assertSet('confirmingRevokeId', $assignment->id)
            ->call('revoke');

        $this->assertDatabaseMissing('role_assignments', ['id' => $assignment->id]);
    }

    // --- Cms\Manage (also the second real x-ui.modal wiring) --------------

    public function test_cms_manage_renders_page_and_edit_modal_reaches_the_same_methods(): void
    {
        $page = ContentPage::create(['slug' => 'zzyx-page', 'title' => 'Zzyx Page', 'content' => 'Body', 'is_active' => true]);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(CmsManage::class);
        $component->assertSee('Zzyx Page')
            ->assertSee('Published');

        // Key interaction: editPage() (a real wire:click) opens the same
        // x-ui.modal-driven flow the hand-rolled modal used to (identical
        // method/property names), and updatePage() still persists real
        // DB state.
        $component->call('editPage', $page->id)
            ->assertSet('showEditPageModal', true)
            ->assertSeeHtml('wire:click="updatePage"')
            ->set('editPageTitle', 'Zzyx Page Updated')
            ->call('updatePage');

        $this->assertSame('Zzyx Page Updated', $page->fresh()->title);
        $component->assertSet('showEditPageModal', false);
    }

    public function test_cms_manage_delete_page_modal_reaches_the_same_methods(): void
    {
        $page = ContentPage::create(['slug' => 'zzyx-page-2', 'title' => 'Zzyx Page Two', 'content' => 'Body', 'is_active' => true]);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(CmsManage::class)
            ->call('confirmDeletePage', $page->id)
            ->assertSet('confirmingDeletePageId', $page->id)
            ->assertSeeHtml('wire:click="deletePage"');

        $component->call('deletePage');

        $this->assertDatabaseMissing('content_pages', ['id' => $page->id]);
    }

    // --- Plans\Manage --------------------------------------------------------

    public function test_plans_manage_renders_plan_and_toggle_active_reaches_real_method(): void
    {
        $plan = Plan::create([
            'name' => 'Zzyx Plan', 'slug' => 'zzyx-plan-'.Str::random(6),
            'plan_family' => 'customer_membership', 'scope_type' => 'global',
            'eligible_actor_type' => 'customer', 'billing_cycle' => 'monthly',
            'price' => 99, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(PlansManage::class);
        $component->assertSee('Zzyx Plan')
            ->assertSee('active');

        // Key interaction: the x-ui.button wired to toggleActive() still
        // calls the real Livewire method and flips real DB state.
        $component->call('toggleActive', $plan->id);

        $this->assertFalse($plan->fresh()->is_active);
        $component->assertSee('inactive');
    }

    // --- Bookings\Index ------------------------------------------------------

    public function test_bookings_index_renders_booking_and_status_filter_switches_results(): void
    {
        $scenario = $this->makeBookingScenario('completed');
        $scenario['booking']->update(['code' => 'ZZYX-BOOKING-CODE']);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(BookingsIndex::class);
        $component->assertSee('ZZYX-BOOKING-CODE')
            ->assertSee('completed');

        // Key interaction: the status-filter button is a real wire:click
        // reaching the real $statusFilter property, narrowing results.
        $component->set('statusFilter', 'pending')
            ->assertDontSee('ZZYX-BOOKING-CODE');
    }

    // --- Operations\Health -----------------------------------------------

    public function test_operations_health_renders_failed_job_and_retry_reaches_real_method(): void
    {
        $admin = $this->makeUserWithPermission('operations.manage', 'global');
        $this->grantPermission($admin, 'operations.view');

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ZzyxJob', 'data' => []]),
            'exception' => "RuntimeException\n#0 {main}", 'failed_at' => now(),
        ]);
        $uuid = DB::table('failed_jobs')->value('uuid');

        $component = Livewire::actingAs($admin)->test(OperationsHealth::class);
        $component->assertSee('ZzyxJob')
            ->assertSeeHtml('wire:click="retryJob(\''.$uuid.'\')"');

        // Key interaction: the x-ui.button wired to retryJob() still
        // reaches the real Livewire method (verified via the same
        // activity-log side effect OperationsExpansionTest itself checks).
        $component->call('retryJob', $uuid);

        $this->assertDatabaseHas('activity_log', ['subject_type' => 'failed_job', 'causer_id' => $admin->id]);
    }

    // --- PerformanceCampaigns\Manage --------------------------------------

    public function test_performance_campaigns_manage_renders_campaign_and_lifecycle_action_reaches_real_method(): void
    {
        $campaign = PerformanceCampaign::create([
            'name' => 'Zzyx Campaign', 'audience_type' => 'provider',
            'metric_key' => 'bookings_completed_count', 'scope_type' => 'global',
            'qualification_mode' => 'threshold', 'target_value' => 2,
            'reward_type' => 'wallet_credit', 'reward_value' => 100,
            'status' => 'draft', 'starts_at' => now(), 'ends_at' => now()->addWeek(),
        ]);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(PerformanceCampaignsManage::class);
        $component->assertSee('Zzyx Campaign')
            ->assertSee('Draft');

        // Key interaction: the x-ui.button (ghost, blue) wired to
        // lifecycleAction() still calls the real Livewire method with the
        // real campaign id + action, moving real DB state.
        $component->call('lifecycleAction', $campaign->id, 'schedule');

        $this->assertSame('scheduled', $campaign->fresh()->status);
        $component->assertSee('Scheduled');
    }

    // --- FlashSales\Manage -------------------------------------------------

    public function test_flash_sales_manage_renders_sale_and_lifecycle_action_reaches_real_method(): void
    {
        $sale = FlashSale::create([
            'name' => 'Zzyx Sale', 'customer_title' => 'Zzyx Sale!', 'type' => 'urgent_sale',
            'status' => 'draft', 'scope_type' => 'global', 'discount_type' => 'percent',
            'discount_value' => 20, 'min_final_price' => 0,
        ]);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(FlashSalesManage::class);
        $component->assertSee('Zzyx Sale')
            ->assertSee('Draft');

        // Key interaction: startScheduling() (a real wire:click on the
        // x-ui.button) opens the real inline scheduling form, and
        // schedule() afterward still moves real DB state.
        $component->call('startScheduling', $sale->id)
            ->set('scheduleStartsAt', now()->toDateTimeString())
            ->set('scheduleEndsAt', now()->addWeek()->toDateTimeString())
            ->call('schedule');

        $this->assertSame('scheduled', $sale->fresh()->status);
        $component->assertSee('Scheduled');
    }
}
