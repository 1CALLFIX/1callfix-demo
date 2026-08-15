<?php

namespace Tests\Feature\Settings;

use App\Models\Commission;
use App\Models\EntitlementBalance;
use App\Models\FlashSale;
use App\Models\Payment;
use App\Models\PerformanceCampaign;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Payout;
use App\Models\Referral;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Livewire\Commissions\Index as CommissionsIndex;
use App\Livewire\Customers\Index as CustomersIndex;
use App\Livewire\Customers\Show as CustomersShow;
use App\Livewire\FlashSales\Manage as FlashSalesManage;
use App\Livewire\Loyalty\Index as LoyaltyIndex;
use App\Livewire\NotificationCenter\Manage as NotificationCenterManage;
use App\Livewire\Operations\Health as OperationsHealth;
use App\Livewire\Payments\Index as PaymentsIndex;
use App\Livewire\Payouts\Manage as PayoutsManage;
use App\Livewire\PerformanceCampaigns\Manage as PerformanceCampaignsManage;
use App\Livewire\Plans\Manage as PlansManage;
use App\Livewire\Settings\Manage as SettingsManage;
use App\Livewire\Subscriptions\Index as SubscriptionsIndex;
use App\Livewire\WalletLedger\Index as WalletLedgerIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase 21 item TECH-2. `Setting::get('locale.currency_symbol', '₹', $scope)`
 * is real, admin-editable, scope-cascaded -- before this phase only
 * DocumentService (invoices/receipts) read it; 14 other admin Blade views
 * hardcoded the literal ₹ character directly. Proves, per screen, that the
 * CONFIGURED symbol renders where ₹ used to be hardcoded -- not merely that
 * the mechanism exists somewhere, but that each of the 14 real display
 * locations now reads it. Every test sets a deliberately distinctive symbol
 * (¥, never used as a real default anywhere in this codebase) so a stray
 * leftover hardcoded ₹ would make the assertion fail, not pass by accident.
 *
 * Every test acts as a super_admin -- this suite is about currency display,
 * not row-level authorization (already covered elsewhere, e.g.
 * RowLevelScopeAuthorizationTest for several of these same screens).
 */
class CurrencySymbolDisplayTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    private const SYMBOL = '¥';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('locale.currency_symbol', self::SYMBOL, 'global');
    }

    public function test_commissions_index_shows_the_configured_symbol(): void
    {
        $scenario = $this->makeBookingScenario('completed');
        Commission::create(['booking_id' => $scenario['booking']->id, 'provider_commission' => 80, 'franchise_commission' => 10, 'platform_commission' => 10]);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(CommissionsIndex::class)->assertSee(self::SYMBOL);
    }

    public function test_customers_index_shows_the_configured_symbol(): void
    {
        $customer = $this->makeCustomer();
        Wallet::create(['user_id' => $customer->id, 'balance' => 250]);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(CustomersIndex::class)->assertSee(self::SYMBOL);
    }

    public function test_customers_show_shows_the_configured_symbol(): void
    {
        $customer = $this->makeCustomer();
        Wallet::create(['user_id' => $customer->id, 'balance' => 250]);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(CustomersShow::class, ['customerId' => $customer->id])->assertSee(self::SYMBOL);
    }

    public function test_flash_sales_manage_shows_the_configured_symbol(): void
    {
        FlashSale::create([
            'name' => 'Test Sale', 'customer_title' => 'Test Sale!', 'type' => 'urgent_sale',
            'status' => 'draft', 'scope_type' => 'global', 'discount_type' => 'flat',
            'discount_value' => 100, 'min_final_price' => 0,
        ]);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(FlashSalesManage::class)->assertSee(self::SYMBOL);
    }

    public function test_loyalty_referrals_tab_shows_the_configured_symbol(): void
    {
        $referrer = $this->makeCustomer();
        $referred = $this->makeCustomer();
        Referral::create(['referrer_id' => $referrer->id, 'referred_id' => $referred->id, 'status' => 'rewarded', 'reward_amount' => 50]);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(LoyaltyIndex::class)->set('activeTab', 'referrals')->assertSee(self::SYMBOL);
    }

    /** No data fixture needed -- the campaign-composer placeholder text is always visible, independent of any real campaign existing. */
    public function test_notification_center_compose_placeholder_shows_the_configured_symbol(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(NotificationCenterManage::class)->assertSee(self::SYMBOL);
    }

    public function test_operations_health_shows_the_configured_symbol(): void
    {
        $customer = $this->makeCustomer();
        Payment::create([
            'purpose' => 'wallet_topup', 'user_id' => $customer->id, 'amount' => 500,
            'gateway' => 'razorpay', 'gateway_order_id' => 'order_orphan_'.uniqid(), 'status' => 'captured', 'captured_at' => now(),
        ]);
        // Deliberately no matching wallet_transactions row -- exactly
        // ReconciliationServiceTest's own "captured without credit" fixture
        // shape, the real path that reaches this screen's $currencySymbol line.
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(OperationsHealth::class)->assertSee(self::SYMBOL);
    }

    public function test_payments_index_shows_the_configured_symbol(): void
    {
        $scenario = $this->makeBookingScenario();
        Payment::create(['purpose' => 'booking', 'booking_id' => $scenario['booking']->id, 'amount' => 300, 'status' => 'captured', 'gateway' => 'razorpay']);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(PaymentsIndex::class)->assertSee(self::SYMBOL);
    }

    public function test_payouts_manage_shows_the_configured_symbol(): void
    {
        $scenario = $this->makeBookingScenario();
        Payout::create(['payee_type' => 'provider', 'payee_id' => $scenario['provider']->id, 'amount' => 500, 'status' => 'pending', 'period_start' => now()->subDays(7), 'period_end' => now()]);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(PayoutsManage::class)->assertSee(self::SYMBOL);
    }

    public function test_performance_campaigns_manage_shows_the_configured_symbol(): void
    {
        // reward_type: 'wallet_credit' is this fixture pattern's own default
        // (PerformanceCampaignEngineTest::makeCampaign()) -- the exact branch
        // that displays a currency symbol rather than 'pts'.
        PerformanceCampaign::create([
            'name' => 'Test Campaign', 'audience_type' => 'provider',
            'metric_key' => 'bookings_completed_count', 'scope_type' => 'global',
            'qualification_mode' => 'threshold', 'target_value' => 2,
            'reward_type' => 'wallet_credit', 'reward_value' => 100,
            'status' => 'draft',
        ]);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(PerformanceCampaignsManage::class)->assertSee(self::SYMBOL);
    }

    public function test_plans_manage_shows_the_configured_symbol(): void
    {
        $plan = Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-plan-'.uniqid(),
            'plan_family' => 'customer_membership', 'scope_type' => 'global',
            'eligible_actor_type' => 'customer', 'billing_cycle' => 'monthly',
            'price' => 199, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);
        PlanEntitlement::create([
            'plan_id' => $plan->id, 'entitlement_type' => 'monetary_allowance',
            'monetary_value' => 500, 'usage_period' => 'monthly',
            'consumption_trigger' => 'booking_created', 'rollover_policy' => 'none',
        ]);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(PlansManage::class)->assertSee(self::SYMBOL);
    }

    /** No data fixture needed -- the Loyalty tab's "points / <symbol> spent" and "points per <symbol>" labels render unconditionally once that tab is active (the default active tab is 'dispatch'). */
    public function test_settings_manage_shows_the_configured_symbol(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(SettingsManage::class)
            ->set('activeTab', 'loyalty')
            ->assertSee(self::SYMBOL);
    }

    public function test_subscriptions_index_shows_the_configured_symbol(): void
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
            'subscribable_type' => \App\Models\User::class, 'subscribable_id' => $customer->id,
            'plan_id' => $plan->id, 'status' => 'active',
            'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);
        EntitlementBalance::create([
            'subscription_id' => $subscription->id, 'plan_entitlement_id' => $entitlement->id,
            'period_start' => now(), 'period_end' => now()->addMonth(),
            'granted_monetary_value' => 500, 'status' => 'current',
        ]);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(SubscriptionsIndex::class)->assertSee(self::SYMBOL);
    }

    public function test_wallet_ledger_index_shows_the_configured_symbol(): void
    {
        $customer = $this->makeCustomer();
        $wallet = Wallet::create(['user_id' => $customer->id, 'balance' => 100]);
        WalletTransaction::create(['wallet_id' => $wallet->id, 'amount' => 100, 'is_credit' => true, 'reason' => 'test', 'ref' => 'test:'.uniqid(), 'status' => 'successful']);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(WalletLedgerIndex::class)->assertSee(self::SYMBOL);
    }

    // ============================== Display-only, not a stored-value change ==============================

    /**
     * The acceptance criteria this item was built against explicitly
     * requires proving changing the Setting affects DISPLAY only, never a
     * stored monetary value -- this is structurally guaranteed (Setting and
     * commissions/payments/payouts are entirely separate tables, no code
     * path in this change touches a monetary column), but proven directly
     * here rather than only asserted in the commit message.
     */
    public function test_changing_the_symbol_does_not_alter_the_stored_commission_value(): void
    {
        $scenario = $this->makeBookingScenario('completed');
        $commission = Commission::create(['booking_id' => $scenario['booking']->id, 'provider_commission' => 123.45, 'franchise_commission' => 6.78, 'platform_commission' => 9.01]);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(CommissionsIndex::class)->assertSee(self::SYMBOL);

        Setting::set('locale.currency_symbol', '$', 'global');
        Livewire::actingAs($admin)->test(CommissionsIndex::class)->assertSee('$')->assertDontSee(self::SYMBOL);

        // The stored figures never moved -- only which symbol prefixes them on screen.
        $this->assertEquals(123.45, (float) $commission->fresh()->provider_commission);
        $this->assertEquals(6.78, (float) $commission->fresh()->franchise_commission);
        $this->assertEquals(9.01, (float) $commission->fresh()->platform_commission);
    }

    public function test_default_symbol_is_still_the_rupee_when_no_setting_is_configured(): void
    {
        // setUp() sets a global override for every other test in this file;
        // this one deliberately clears it to prove the ₹ fallback default
        // (Setting::get('locale.currency_symbol', '₹')) still works, not
        // just that the configured-symbol path works.
        Setting::clear('locale.currency_symbol', 'global', null);

        $scenario = $this->makeBookingScenario('completed');
        Commission::create(['booking_id' => $scenario['booking']->id, 'provider_commission' => 80, 'franchise_commission' => 10, 'platform_commission' => 10]);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(CommissionsIndex::class)->assertSee('₹');
    }
}
