<?php

namespace Tests\Feature\Earnings;

use App\Livewire\Settings\Manage;
use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — Stage 2: settings cross-validation,
 * settings audit trail, append-only activity_log, Super Admin gate, and the
 * pre-deploy settings report.
 */
class RuleOfLawFoundationTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private function walletForm(array $overrides = []): array
    {
        return array_merge([
            'walletCustomerMinTopup' => '100',
            'walletCustomerMaxTopup' => '10000',
            'walletCustomerMaxBalance' => '50000',
            'walletCustomerDailyTopupLimit' => '20000',
            'walletCustomerMonthlyTopupLimit' => '100000',
            'walletProviderMinBalanceToAcceptJobs' => '0',
            'walletProviderMinPayoutAmount' => '0',
            'walletProviderMaxPayoutAmount' => '0',
            'walletFranchiseMinPayoutAmount' => '0',
            'walletFranchiseMaxPayoutAmount' => '0',
        ], $overrides);
    }

    private function saveWallet(array $overrides = [], $actor = null)
    {
        $component = Livewire::actingAs($actor ?? $this->makeSuperAdmin())->test(Manage::class)->set('activeTab', 'wallet');
        foreach ($this->walletForm($overrides) as $prop => $value) {
            $component->set($prop, $value);
        }

        return $component->call('saveWallet');
    }

    // ───────────────────────── 2.3 cross-validation ─────────────────────────

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function contradictions(): array
    {
        return [
            'max top-up above daily limit' => [['walletCustomerMaxTopup' => '30000'], 'walletCustomerMaxTopup'],
            'daily limit above monthly limit' => [['walletCustomerDailyTopupLimit' => '200000'], 'walletCustomerDailyTopupLimit'],
            'max top-up above max balance' => [['walletCustomerMaxTopup' => '15000', 'walletCustomerMaxBalance' => '12000'], 'walletCustomerMaxTopup'],
            'min top-up above max top-up' => [['walletCustomerMinTopup' => '20000'], 'walletCustomerMinTopup'],
        ];
    }

    #[DataProvider('contradictions')]
    public function test_wallet_settings_contradictions_are_rejected(array $overrides, string $field): void
    {
        $this->saveWallet($overrides)->assertHasErrors([$field]);

        $this->assertNull(Setting::get('wallet.customer_max_topup'), 'nothing was saved');
    }

    public function test_consistent_wallet_settings_save(): void
    {
        $this->saveWallet()->assertHasNoErrors();

        $this->assertSame('10000', Setting::get('wallet.customer_max_topup'));
    }

    public function test_blank_wallet_fields_save_as_unset_not_as_a_default(): void
    {
        Setting::set('wallet.customer_max_balance', '50000');

        $this->saveWallet(['walletCustomerMaxBalance' => ''])->assertHasNoErrors();

        $this->assertNull(Setting::get('wallet.customer_max_balance'));
    }

    public function test_an_unset_key_loads_as_blank_in_the_form(): void
    {
        Livewire::actingAs($this->makeSuperAdmin())->test(Manage::class)
            ->assertSet('walletCustomerMinTopup', '')
            ->assertSet('loyaltyPointsExpiryDays', '')
            ->assertSet('referralRewardAmount', '');
    }

    // ───────────────────────── 2.4 audit ─────────────────────────

    public function test_every_changed_key_writes_one_audit_entry_with_old_and_new(): void
    {
        $admin = $this->makeSuperAdmin();
        Setting::set('wallet.customer_min_topup', '50');

        $this->saveWallet([], $admin)->assertHasNoErrors();

        $entry = ActivityLog::where('subject_type', 'setting')
            ->get()->first(fn ($l) => $l->properties['key'] === 'wallet.customer_min_topup');

        $this->assertNotNull($entry);
        $this->assertSame($admin->id, $entry->causer_id);
        $this->assertSame('global', $entry->properties['scope_type']);
        $this->assertNull($entry->properties['scope_id']);
        $this->assertSame('50', $entry->properties['old']);
        $this->assertSame('100', $entry->properties['new']);
        $this->assertNotNull($entry->created_at);
    }

    public function test_unchanged_keys_are_not_logged(): void
    {
        $this->saveWallet()->assertHasNoErrors();
        $first = ActivityLog::where('subject_type', 'setting')->count();

        $this->saveWallet()->assertHasNoErrors();

        $this->assertSame($first, ActivityLog::where('subject_type', 'setting')->count(), 'a re-save of identical values logs nothing');
    }

    public function test_a_non_earnings_tab_is_audited_too(): void
    {
        Livewire::actingAs($this->makeSuperAdmin())->test(Manage::class)
            ->set('activeTab', 'dispatch')
            ->set('dispatchOfferBatchSize', '7')
            ->call('saveDispatch')
            ->assertHasNoErrors();

        $keys = ActivityLog::where('subject_type', 'setting')->get()->map(fn ($l) => $l->properties['key'])->all();
        $this->assertContains('dispatch.offer_batch_size', $keys);
    }

    public function test_scoped_saves_record_the_scope(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();

        Livewire::actingAs($this->makeSuperAdmin())->test(Manage::class)
            ->set('scopeType', 'franchise')->set('scopeFranchiseId', $franchise->id)
            ->set('activeTab', 'dispatch')
            ->set('dispatchOfferBatchSize', '9')
            ->call('saveDispatch')
            ->assertHasNoErrors();

        $entry = ActivityLog::where('subject_type', 'setting')->get()->first(fn ($l) => $l->properties['key'] === 'dispatch.offer_batch_size');
        $this->assertSame('franchise', $entry->properties['scope_type']);
        $this->assertSame($franchise->id, $entry->properties['scope_id']);
    }

    public function test_clearing_an_override_is_audited(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        Setting::set('dispatch.offer_batch_size', '9', 'franchise', $franchise->id);

        Livewire::actingAs($this->makeSuperAdmin())->test(Manage::class)
            ->set('scopeType', 'franchise')->set('scopeFranchiseId', $franchise->id)
            ->call('clearOverride', 'dispatch.offer_batch_size');

        $entry = ActivityLog::where('subject_type', 'setting')->latest('id')->first();
        $this->assertSame('dispatch.offer_batch_size', $entry->properties['key']);
        $this->assertSame('9', $entry->properties['old']);
        $this->assertNull($entry->properties['new']);
    }

    // ───────────────────────── 2.5 append-only ─────────────────────────

    public function test_activity_log_rows_cannot_be_updated(): void
    {
        $log = ActivityLog::create(['subject_type' => 'x', 'subject_id' => 1, 'description' => 'original']);

        $this->expectException(\LogicException::class);
        $log->update(['description' => 'tampered']);
    }

    public function test_activity_log_rows_cannot_be_deleted(): void
    {
        $log = ActivityLog::create(['subject_type' => 'x', 'subject_id' => 1, 'description' => 'original']);

        $this->expectException(\LogicException::class);
        $log->delete();
    }

    public function test_activity_log_mass_update_and_delete_are_blocked_too(): void
    {
        ActivityLog::create(['subject_type' => 'x', 'subject_id' => 1, 'description' => 'original']);

        try {
            ActivityLog::query()->where('subject_type', 'x')->update(['description' => 'tampered']);
            $this->fail('mass update allowed');
        } catch (\LogicException) {
        }

        try {
            ActivityLog::query()->where('subject_type', 'x')->delete();
            $this->fail('mass delete allowed');
        } catch (\LogicException) {
        }

        $this->assertSame('original', ActivityLog::where('subject_type', 'x')->value('description'));
    }

    // ───────────────────────── 2.6 Super Admin gate ─────────────────────────

    /** @return array<string, array{0: string}> */
    public static function superAdminOnlySaves(): array
    {
        return ['wallet' => ['saveWallet'], 'loyalty / referral' => ['saveLoyalty']];
    }

    #[DataProvider('superAdminOnlySaves')]
    public function test_settings_manage_holder_gets_403_on_earnings_policy_saves(string $method): void
    {
        $actor = $this->makeUserWithPermission('settings.manage', 'global');
        Setting::set('wallet.customer_min_topup', '100');

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('walletCustomerMinTopup', '1')
            ->call($method)
            ->assertForbidden();

        $this->assertSame('100', Setting::get('wallet.customer_min_topup'));
    }

    public function test_settings_manage_holder_cannot_clear_an_earnings_override(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        Setting::set('wallet.customer_min_topup', '100', 'franchise', $franchise->id);

        Livewire::actingAs($this->makeUserWithPermission('settings.manage', 'global'))->test(Manage::class)
            ->set('scopeType', 'franchise')->set('scopeFranchiseId', $franchise->id)
            ->call('clearOverride', 'wallet.customer_min_topup')
            ->assertForbidden();

        $this->assertTrue(Setting::existsAt('wallet.customer_min_topup', 'franchise', $franchise->id));
    }

    public function test_settings_manage_holder_still_saves_other_tabs(): void
    {
        Livewire::actingAs($this->makeUserWithPermission('settings.manage', 'global'))->test(Manage::class)
            ->set('dispatchOfferBatchSize', '6')
            ->call('saveDispatch')
            ->assertHasNoErrors();

        $this->assertSame('6', Setting::get('dispatch.offer_batch_size'));
    }

    // ───────────────────────── 2.7 settings report ─────────────────────────

    public function test_settings_report_prints_every_key_and_unset(): void
    {
        Setting::set('wallet.topup_enabled', '1');

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('earnings:settings-report'));
        $out = \Illuminate\Support\Facades\Artisan::output();

        foreach (array_keys(\App\Support\EarningsSettings::KEYS) as $key) {
            $this->assertStringContainsString($key, $out);
        }
        $this->assertMatchesRegularExpression('/wallet\.topup_enabled\s*\|\s*1\s*\|/', $out);
        $this->assertMatchesRegularExpression('/loyalty\.redeem_enabled\s*\|\s*UNSET\s*\|/', $out);
        $this->assertStringContainsString('SELECT scope_type, scope_id, `key`, value, updated_at', $out);
    }
}
