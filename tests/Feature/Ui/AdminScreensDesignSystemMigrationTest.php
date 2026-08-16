<?php

namespace Tests\Feature\Ui;

use App\Livewire\Commissions\Index as CommissionsIndex;
use App\Livewire\Customers\Index as CustomersIndex;
use App\Livewire\Customers\Show as CustomersShow;
use App\Livewire\Payments\Index as PaymentsIndex;
use App\Livewire\Providers\Index as ProvidersIndex;
use App\Livewire\WalletLedger\Index as WalletLedgerIndex;
use App\Livewire\Workers\Index as WorkersIndex;
use App\Models\Commission;
use App\Models\Payment;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
