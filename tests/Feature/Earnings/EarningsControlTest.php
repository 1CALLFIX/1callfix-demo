<?php

namespace Tests\Feature\Earnings;

use App\Exceptions\WalletFrozenException;
use App\Livewire\EarningsControl\Manage;
use App\Models\ActivityLog;
use App\Models\LoyaltyPoint;
use App\Models\Referral;
use App\Models\Setting;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Earnings\EarningsMonitor;
use App\Services\Earnings\WalletAdjustmentService;
use App\Services\Earnings\WalletFreezeService;
use App\Services\LoyaltyService;
use App\Services\PayoutService;
use App\Services\ReferralService;
use App\Services\WalletService;
use App\Services\WalletTopUpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\EarningsSettingsFixture;
use Tests\TestCase;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — Stage 3: Earnings Control (switches,
 * limits, freeze, adjustments, ledger viewer, monitoring) and its
 * Super-Admin-only gate.
 */
class EarningsControlTest extends TestCase
{
    use BookingFixtureHelpers;
    use EarningsSettingsFixture;
    use RbacTestHelpers;
    use RefreshDatabase;

    // ───────────────────────── gate ─────────────────────────

    public function test_super_admin_can_open_every_tab(): void
    {
        $component = Livewire::actingAs($this->makeSuperAdmin())->test(Manage::class);

        foreach (['switches', 'freeze', 'adjust', 'ledger', 'monitoring'] as $tab) {
            $component->call('setTab', $tab)->assertOk();
        }
    }

    public function test_settings_manage_holder_gets_403_on_the_screen(): void
    {
        $this->actingAs($this->makeUserWithPermission('settings.manage', 'global'))
            ->get(route('admin.earnings-control.index'))
            ->assertForbidden();
    }

    /** @return array<string, array{0: string, 1: array}> */
    public static function policyActions(): array
    {
        return [
            'switch' => ['setSwitch', ['earnings.enabled', '1']],
            'limits' => ['saveLimits', []],
            'freeze' => ['freeze', []],
            'unfreeze' => ['unfreeze', []],
            'adjust' => ['adjust', []],
            'select user' => ['selectUser', [1]],
        ];
    }

    /**
     * Direct request: the component is loaded by a Super Admin, then the
     * very next call arrives from a settings.manage holder — every action
     * re-checks the role server-side and refuses.
     */
    #[DataProvider('policyActions')]
    public function test_non_super_admin_gets_403_on_every_policy_action(string $method, array $args): void
    {
        $component = Livewire::actingAs($this->makeSuperAdmin())->test(Manage::class)
            ->set('adjustAmount', '10')->set('adjustReason', 'x');

        $this->actingAs($this->makeUserWithPermission('settings.manage', 'global'));

        $component->call($method, ...$args)->assertForbidden();

        $this->assertNull(Setting::get('earnings.enabled'));
    }

    // ───────────────────────── switches & limits ─────────────────────────

    public function test_switch_on_off_unset_at_global_scope_with_audit(): void
    {
        $component = Livewire::actingAs($admin = $this->makeSuperAdmin())->test(Manage::class);

        $component->call('setSwitch', 'loyalty.redeem_enabled', '1');
        $this->assertSame('1', Setting::get('loyalty.redeem_enabled'));

        $component->call('setSwitch', 'loyalty.redeem_enabled', '0');
        $this->assertSame('0', Setting::get('loyalty.redeem_enabled'));

        $component->call('setSwitch', 'loyalty.redeem_enabled', 'unset');
        $this->assertNull(Setting::get('loyalty.redeem_enabled'));

        $trail = ActivityLog::where('subject_type', 'setting')->orderBy('id')->get()->map(fn ($l) => [$l->properties['old'], $l->properties['new'], $l->causer_id])->all();
        $this->assertSame([[null, '1', $admin->id], ['1', '0', $admin->id], ['0', null, $admin->id]], $trail);
    }

    public function test_a_franchise_override_is_written_at_that_scope_only(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();

        Livewire::actingAs($this->makeSuperAdmin())->test(Manage::class)
            ->set('scopeType', 'franchise')->set('scopeFranchiseId', $franchise->id)
            ->call('setSwitch', 'wallet.topup_enabled', '1');

        $this->assertTrue(Setting::existsAt('wallet.topup_enabled', 'franchise', $franchise->id));
        $this->assertNull(Setting::get('wallet.topup_enabled'));
        $this->assertSame('1', Setting::get('wallet.topup_enabled', null, ['franchise_id' => $franchise->id]));
    }

    public function test_global_only_limits_ignore_the_scope_picker(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();

        Livewire::actingAs($this->makeSuperAdmin())->test(Manage::class)
            ->set('scopeType', 'franchise')->set('scopeFranchiseId', $franchise->id)
            ->set('limitInputs.wallet__admin_adjustment_max', '500')
            ->set('limitInputs.referral__max_per_customer', '3')
            ->call('saveLimits')
            ->assertHasNoErrors();

        $this->assertSame('500', Setting::where('key', 'wallet.admin_adjustment_max')->where('scope_type', 'global')->value('value'));
        $this->assertTrue(Setting::existsAt('referral.max_per_customer', 'franchise', $franchise->id));
    }

    // ───────────────────────── freeze ─────────────────────────

    private function freeze($user, string $reason = 'suspected abuse'): void
    {
        app(WalletFreezeService::class)->freeze($this->makeSuperAdmin(), $user, $reason);
    }

    public function test_freeze_and_unfreeze_need_a_reason_and_are_audited(): void
    {
        $admin = $this->makeSuperAdmin();
        $customer = $this->makeCustomer();

        try {
            app(WalletFreezeService::class)->freeze($admin, $customer, '  ');
            $this->fail('freeze without a reason');
        } catch (\RuntimeException) {
        }

        app(WalletFreezeService::class)->freeze($admin, $customer, 'chargeback pattern');
        $wallet = Wallet::where('user_id', $customer->id)->first();
        $this->assertNotNull($wallet->frozen_at);
        $this->assertSame('chargeback pattern', $wallet->frozen_reason);
        $this->assertSame($admin->id, $wallet->frozen_by);

        app(WalletFreezeService::class)->unfreeze($admin, $customer, 'cleared by review');
        $this->assertNull($wallet->fresh()->frozen_at);

        $this->assertSame(2, ActivityLog::where('subject_type', Wallet::class)->where('subject_id', $wallet->id)->count());
    }

    public function test_frozen_wallet_blocks_paying_from_wallet(): void
    {
        $this->configureWalletPayments();
        ['booking' => $booking, 'customer' => $customer] = $this->makeBookingScenario();
        Wallet::create(['user_id' => $customer->id, 'balance' => 5000]);
        $this->freeze($customer);

        $method = new \ReflectionMethod(\App\Actions\CreateBookingAction::class, 'payWithWallet');

        $this->expectException(WalletFrozenException::class);
        $method->invoke(app(\App\Actions\CreateBookingAction::class), $booking->fresh());
    }

    public function test_frozen_wallet_blocks_a_payout_request(): void
    {
        ['provider' => $provider] = $this->makeBookingScenario();
        app(WalletService::class)->credit($provider->user, 1000, 'earning', 'booking:999:provider-earning');
        $this->freeze($provider->user);

        $this->expectException(WalletFrozenException::class);
        app(PayoutService::class)->request('provider', $provider->id, 100);
    }

    public function test_frozen_wallet_blocks_a_topup_request(): void
    {
        $this->configureLegacyWalletTopUp();
        $customer = $this->makeCustomer();
        $this->freeze($customer);

        $this->expectException(WalletFrozenException::class);
        app(WalletTopUpService::class)->requestTopUp($customer, 500);
    }

    public function test_frozen_wallet_blocks_loyalty_redemption_and_keeps_the_points(): void
    {
        $this->configureLegacyLoyalty();
        $customer = $this->makeCustomer();
        app(LoyaltyService::class)->earn($customer, 500, 'promo');
        $this->freeze($customer);

        try {
            app(LoyaltyService::class)->redeem($customer, 200);
            $this->fail('redeemed into a frozen wallet');
        } catch (WalletFrozenException) {
        }

        $this->assertSame(500, app(LoyaltyService::class)->balance($customer));
        $this->assertSame(0, LoyaltyPoint::where('user_id', $customer->id)->where('reason', 'redeemed')->count());
    }

    public function test_frozen_wallet_blocks_a_referral_credit_and_leaves_it_pending(): void
    {
        $this->configureLegacyReferral();
        ['booking' => $booking, 'customer' => $referred] = $this->makeBookingScenario('completed');
        $referrer = $this->makeCustomer();
        $referral = Referral::create(['referrer_id' => $referrer->id, 'referred_id' => $referred->id, 'status' => 'pending']);
        $this->freeze($referrer);

        $this->assertNull(app(ReferralService::class)->qualifyFromCompletedBooking($booking));
        $this->assertSame('pending', $referral->fresh()->status);
        $this->assertSame(0.0, (float) app(WalletService::class)->balance($referrer));
        $this->assertTrue(ActivityLog::where('description', 'like', 'Referral reward withheld%')->exists());
    }

    public function test_frozen_wallet_blocks_an_admin_credit(): void
    {
        Setting::set('wallet.admin_adjustment_max', '1000');
        $customer = $this->makeCustomer();
        $this->freeze($customer);

        $this->expectException(WalletFrozenException::class);
        app(WalletAdjustmentService::class)->adjust($this->makeSuperAdmin(), $customer, 'credit', 50, 'goodwill');
    }

    public function test_a_refund_still_credits_a_frozen_wallet_and_is_flagged(): void
    {
        $customer = $this->makeCustomer();
        $this->freeze($customer);

        app(WalletService::class)->credit($customer, 300, 'Refund for cancelled booking', 'booking:77:wallet-refund');

        $this->assertEqualsWithDelta(300.0, (float) app(WalletService::class)->balance($customer), 0.001);
        $flagged = app(EarningsMonitor::class)->creditsToFrozenWallets();
        $this->assertTrue($flagged->contains('ref', 'booking:77:wallet-refund'));
    }

    // ───────────────────────── adjustments ─────────────────────────

    public function test_wallet_adjustment_is_a_new_row_with_actor_and_audit(): void
    {
        Setting::set('wallet.admin_adjustment_max', '1000');
        $admin = $this->makeSuperAdmin();
        $customer = $this->makeCustomer();
        $original = app(WalletService::class)->credit($customer, 100, 'Refund', 'booking:5:wallet-refund');
        $originalSnapshot = $original->fresh()->toArray();

        $txn = app(WalletAdjustmentService::class)->adjust($admin, $customer, 'credit', 250, 'missed refund');

        $this->assertStringStartsWith('admin-adjust:', $txn->ref);
        $this->assertSame($admin->id, $txn->actor_id);
        $this->assertTrue($txn->is_credit);
        $this->assertStringContainsString('missed refund', $txn->reason);
        $this->assertEqualsWithDelta(350.0, (float) app(WalletService::class)->balance($customer), 0.001);
        $this->assertSame($originalSnapshot, $original->fresh()->toArray(), 'original row untouched');

        $log = ActivityLog::where('subject_type', 'wallet')->latest('id')->first();
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame('missed refund', $log->properties['reason']);
        $this->assertSame($txn->ref, $log->properties['ref']);
    }

    public function test_wallet_adjustment_requires_a_reason(): void
    {
        Setting::set('wallet.admin_adjustment_max', '1000');

        $this->expectExceptionMessage('reason is required');
        app(WalletAdjustmentService::class)->adjust($this->makeSuperAdmin(), $this->makeCustomer(), 'credit', 10, '   ');
    }

    public function test_wallet_adjustment_cap_is_enforced_and_unset_means_disabled(): void
    {
        $admin = $this->makeSuperAdmin();
        $customer = $this->makeCustomer();

        try {
            app(WalletAdjustmentService::class)->adjust($admin, $customer, 'credit', 10, 'x');
            $this->fail('adjusted with no cap configured');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('disabled', $e->getMessage());
        }

        Setting::set('wallet.admin_adjustment_max', '100');
        try {
            app(WalletAdjustmentService::class)->adjust($admin, $customer, 'credit', 100.01, 'x');
            $this->fail('adjusted above the cap');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('maximum', $e->getMessage());
        }

        $this->assertSame(0, WalletTransaction::where('ref', 'like', 'admin-adjust:%')->count());
    }

    public function test_wallet_adjustment_debit_cannot_go_negative(): void
    {
        Setting::set('wallet.admin_adjustment_max', '1000');
        $customer = $this->makeCustomer();
        app(WalletService::class)->credit($customer, 40, 'Refund', 'booking:6:wallet-refund');

        try {
            app(WalletAdjustmentService::class)->adjust($this->makeSuperAdmin(), $customer, 'debit', 50, 'correction');
            $this->fail('debited below zero');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Insufficient', $e->getMessage());
        }

        $this->assertEqualsWithDelta(40.0, (float) app(WalletService::class)->balance($customer), 0.001);
    }

    public function test_wallet_adjustment_through_the_screen(): void
    {
        Setting::set('wallet.admin_adjustment_max', '1000');
        $customer = $this->makeCustomer();

        Livewire::actingAs($this->makeSuperAdmin())->test(Manage::class)
            ->call('setTab', 'adjust')
            ->call('selectUser', $customer->id)
            ->set('adjustKind', 'wallet')->set('adjustDirection', 'credit')
            ->set('adjustAmount', '75')->set('adjustReason', 'service failure goodwill')
            ->call('adjust')
            ->assertSet('flashType', 'success');

        $this->assertEqualsWithDelta(75.0, (float) app(WalletService::class)->balance($customer), 0.001);
    }

    public function test_points_adjustment_credit_and_fifo_debit(): void
    {
        $this->configureLegacyLoyalty();
        Setting::set('loyalty.admin_adjustment_max', '500');
        $admin = $this->makeSuperAdmin();
        $customer = $this->makeCustomer();
        app(LoyaltyService::class)->earn($customer, 100, 'promo');

        $credit = app(LoyaltyService::class)->adjust($admin, $customer, 'credit', 200, 'compensation');
        $this->assertSame($admin->id, $credit->actor_id);
        $this->assertStringStartsWith('admin-adjust:', $credit->ref);
        $this->assertSame(300, app(LoyaltyService::class)->balance($customer));

        app(LoyaltyService::class)->adjust($admin, $customer, 'debit', 250, 'double award');
        $this->assertSame(50, app(LoyaltyService::class)->balance($customer));

        try {
            app(LoyaltyService::class)->adjust($admin, $customer, 'debit', 60, 'too much');
            $this->fail('points debited below zero');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Insufficient', $e->getMessage());
        }

        try {
            app(LoyaltyService::class)->adjust($admin, $customer, 'credit', 501, 'over cap');
            $this->fail('points adjusted above cap');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('maximum', $e->getMessage());
        }

        $this->assertSame(2, ActivityLog::where('subject_type', 'loyalty_points')->count());
    }

    public function test_points_adjustment_unset_cap_means_disabled(): void
    {
        $this->configureLegacyLoyalty();

        $this->expectExceptionMessage('disabled');
        app(LoyaltyService::class)->adjust($this->makeSuperAdmin(), $this->makeCustomer(), 'credit', 10, 'x');
    }

    // ───────────────────────── viewer & monitoring ─────────────────────────

    public function test_ledger_viewer_labels_and_filters(): void
    {
        $customer = $this->makeCustomer();
        app(WalletService::class)->credit($customer, 100, 'Refund for booking', 'booking:9:wallet-refund');
        app(WalletService::class)->credit($customer, 20, 'Redeemed points', 'loyalty-redeem:0f8b2c1a-1111-4222-8333-444455556666');

        $component = Livewire::actingAs($this->makeSuperAdmin())->test(Manage::class)
            ->call('setTab', 'ledger')
            ->call('selectUser', $customer->id)
            ->assertSee('Refund')
            ->assertSee('Loyalty points redeemed');

        $component->set('ledgerLabel', 'refund')
            ->assertSee('booking:9:wallet-refund')
            ->assertDontSee('loyalty-redeem:');
    }

    public function test_monitoring_totals_by_label_and_franchise_filter(): void
    {
        $customer = $this->makeCustomer();
        app(WalletService::class)->credit($customer, 100, 'Refund', 'booking:9:wallet-refund');
        app(WalletService::class)->credit($customer, 50, 'Refund', 'booking:10:wallet-refund');

        $totals = app(EarningsMonitor::class)->totalsByLabel(now()->subDay(), now()->addDay());
        $this->assertEqualsWithDelta(150.0, $totals['refund']['credit'], 0.001);
        $this->assertSame(2, $totals['refund']['count']);

        [, , $otherFranchise] = $this->makeFranchiseTree();
        $this->assertSame([], app(EarningsMonitor::class)->totalsByLabel(now()->subDay(), now()->addDay(), $otherFranchise->id));
    }

    public function test_threshold_flags_are_off_when_unset_and_on_when_set(): void
    {
        $customer = $this->makeCustomer();
        app(WalletService::class)->credit($customer, 5000, 'Refund', 'booking:11:wallet-refund');
        $monitor = app(EarningsMonitor::class);

        $this->assertCount(0, $monitor->refundsAboveThreshold(now()->subDay(), now()->addDay()));
        Setting::set('earnings.flag_refund_above', '1000');
        $this->assertCount(1, $monitor->refundsAboveThreshold(now()->subDay(), now()->addDay()));

        $referrer = $this->makeCustomer();
        foreach (range(1, 3) as $i) {
            Referral::create(['referrer_id' => $referrer->id, 'referred_id' => $this->makeCustomer()->id, 'status' => 'rewarded', 'reward_amount' => 50]);
        }
        $this->assertCount(0, $monitor->referrersAboveThreshold());
        Setting::set('earnings.flag_referrals_above', '2');
        $this->assertSame($referrer->id, $monitor->referrersAboveThreshold()->first()->referrer_id);
    }

    public function test_monitoring_tab_renders_every_flag_list(): void
    {
        Livewire::actingAs($this->makeSuperAdmin())->test(Manage::class)
            ->call('setTab', 'monitoring')
            ->assertSee('Frozen wallets')
            ->assertSee('Money credited to frozen wallets')
            ->assertSee('loyalty:balance-audit')
            ->assertSee('bundles:refund-audit');
    }

    // ───────────────────────── ledger immutability ─────────────────────────

    /**
     * No screen, controller or route updates or deletes a wallet_transactions
     * or loyalty_points row. Known, deliberate exceptions outside the
     * request surface: QaCleaner (QA fixtures only, artisan) and Operations →
     * Clear Data (non-production only, mysqldump-backed, table-level, driven
     * from DataClearCatalog — listed in the EARN3 report).
     */
    public function test_no_http_or_livewire_surface_updates_or_deletes_a_ledger_row(): void
    {
        $patterns = [
            '/(WalletTransaction|LoyaltyPoint)::[^;]*->(update|delete|forceDelete)\(/s',
            "/DB::table\\(\\s*'(wallet_transactions|loyalty_points)'\\s*\\)[^;]*->(update|delete)\\(/s",
            '/\$(txn|transaction|walletTransaction|loyaltyPoint|point)->(update|delete|save)\(/',
        ];

        $offenders = [];
        foreach ([app_path('Http'), app_path('Livewire'), base_path('routes')] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                $src = $file->getContents();
                foreach ($patterns as $p) {
                    if (preg_match($p, $src)) {
                        $offenders[] = $file->getRelativePathname();
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)));
    }
}
