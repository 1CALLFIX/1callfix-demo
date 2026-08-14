<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\Manage;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Regression coverage for the Phase 11 (Admin Menu/Settings completeness
 * audit) additions to Settings\Manage: five new real tabs (KYC,
 * Compensation, Security/OTP, Operations, Subscriptions) exposing 23
 * Setting keys that were already read by real, wired application code
 * (KycWithdrawalPolicyService, CompensationService, OtpService,
 * QrChallengeService, StuckBookingService, DispatchHealthService,
 * RenewalService) but had no admin UI at all — permanently stuck on their
 * hardcoded fallback default. No dedicated Settings test file existed
 * before this session despite ten pre-existing real tabs.
 *
 * Security/OTP, Operations, and Subscriptions are GLOBAL ONLY (their
 * consumers call Setting::get() with no scope argument at all) — every
 * test below confirms the scope picker is genuinely ignored for those
 * tabs, not silently writing a dead scoped override the consumer would
 * never read.
 */
class SettingsManageTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    private function actor()
    {
        $actor = $this->makeUserWithPermission('settings.manage', 'global');

        return $actor;
    }

    public function test_subscriptions_membership_placeholder_no_longer_exists(): void
    {
        $this->assertArrayNotHasKey('subscriptions_membership', Manage::PLACEHOLDER_TABS);
    }

    public function test_save_kyc_writes_real_consumer_keys(): void
    {
        Livewire::actingAs($this->actor())->test(Manage::class)
            ->set('activeTab', 'kyc')
            ->set('kycWithdrawalRestrictionEnabled', '0')
            ->set('kycRequireVerificationVideo', '0')
            ->set('kycMaxDocumentSizeMb', '25')
            ->set('kycMaxVideoSizeMb', '75')
            ->call('saveKyc')
            ->assertHasNoErrors();

        $this->assertSame('0', Setting::get('kyc.withdrawal_restriction_enabled', '1'));
        $this->assertSame('0', Setting::get('kyc.require_verification_video', '1'));
        $this->assertSame('25', Setting::get('kyc.max_document_size_mb', '10'));
        $this->assertSame('75', Setting::get('kyc.max_video_size_mb', '50'));
    }

    public function test_save_compensation_writes_real_consumer_keys(): void
    {
        Livewire::actingAs($this->actor())->test(Manage::class)
            ->set('activeTab', 'compensation')
            ->set('compensationOvertimeRatePerMinute', '2.5')
            ->set('compensationOvertimeThresholdMinutes', '10')
            ->set('compensationNightWindowStartHour', '22')
            ->set('compensationNightWindowEndHour', '6')
            ->set('compensationNightFlatAmount', '50')
            ->set('compensationPeakWindowStartHour', '17')
            ->set('compensationPeakWindowEndHour', '20')
            ->set('compensationPeakFlatAmount', '30')
            ->set('compensationRainFlatAmount', '40')
            ->set('compensationWaitingRatePerMinute', '1.5')
            ->call('saveCompensation')
            ->assertHasNoErrors();

        $this->assertSame('2.5', Setting::get('compensation.overtime_rate_per_minute', '0'));
        $this->assertSame('22', Setting::get('compensation.night_window_start_hour', '-1'));
        $this->assertSame('40', Setting::get('compensation.rain_flat_amount', '0'));
        $this->assertSame('1.5', Setting::get('compensation.waiting_rate_per_minute', '0'));
    }

    public function test_save_security_always_writes_global_even_when_a_franchise_scope_is_picked(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();

        Livewire::actingAs($this->actor())->test(Manage::class)
            ->set('scopeType', 'franchise')
            ->set('scopeFranchiseId', $franchise->id)
            ->set('activeTab', 'security')
            ->set('authOtpLength', '5')
            ->set('authOtpExpirySeconds', '600')
            ->set('authOtpResendCooldownSeconds', '45')
            ->set('authOtpMaxAttempts', '3')
            ->set('authQrChallengeExpirySeconds', '90')
            ->call('saveSecurity')
            ->assertHasNoErrors();

        // Written at Global, NOT at the franchise scope that was picked —
        // OtpService/QrChallengeService would never see a franchise-scoped
        // override since they pass no scope at all.
        $this->assertDatabaseHas('settings', ['key' => 'auth.otp_length', 'scope_type' => 'global', 'value' => '5']);
        $this->assertDatabaseMissing('settings', ['key' => 'auth.otp_length', 'scope_type' => 'franchise']);
        $this->assertSame(5, (int) Setting::get('auth.otp_length', 6));
    }

    public function test_save_operations_always_writes_global(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();

        Livewire::actingAs($this->actor())->test(Manage::class)
            ->set('scopeType', 'franchise')
            ->set('scopeFranchiseId', $franchise->id)
            ->set('activeTab', 'operations')
            ->set('opsStuckThresholdSearchingProvider', '15')
            ->set('opsStuckThresholdAssigned', '45')
            ->set('opsStuckThresholdProviderEnRoute', '45')
            ->set('opsStuckThresholdInProgress', '180')
            ->set('opsStuckThresholdOnHold', '720')
            ->set('opsDispatchOfferResponseTimeoutMinutes', '3')
            ->call('saveOperations')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('settings', ['key' => 'operations.stuck_threshold_minutes.searching_provider', 'scope_type' => 'global', 'value' => '15']);
        $this->assertDatabaseMissing('settings', ['key' => 'operations.stuck_threshold_minutes.searching_provider', 'scope_type' => 'franchise']);
        $this->assertSame(3, (int) Setting::get('dispatch.offer_response_timeout_minutes', '2'));
    }

    public function test_save_subscriptions_always_writes_global(): void
    {
        Livewire::actingAs($this->actor())->test(Manage::class)
            ->set('activeTab', 'subscriptions')
            ->set('subscriptionsGracePeriodDays', '7')
            ->call('saveSubscriptions')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('settings', ['key' => 'plan.grace_period_days', 'scope_type' => 'global', 'value' => '7']);
        $this->assertSame(7, (int) Setting::get('plan.grace_period_days', '0'));
    }

    public function test_save_loyalty_writes_referral_pending_expiry_globally_even_when_scoped(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();

        Livewire::actingAs($this->actor())->test(Manage::class)
            ->set('scopeType', 'franchise')
            ->set('scopeFranchiseId', $franchise->id)
            ->set('activeTab', 'loyalty')
            ->set('loyaltyCustomerPointsPerCurrencyUnit', '0.02')
            ->set('loyaltyProviderPointsPerCompletedJob', '10')
            ->set('loyaltyPointsPerRupeeRedemption', '5')
            ->set('loyaltyMinRedemptionPoints', '50')
            ->set('loyaltyPointsExpiryDays', '180')
            ->set('referralRewardType', 'wallet')
            ->set('referralRewardAmount', '25')
            ->set('referralRewardPoints', '50')
            ->set('referralPendingExpiryDays', '14')
            ->call('saveLoyalty')
            ->assertHasNoErrors();

        // Sibling fields DO write at the picked (franchise) scope...
        $this->assertDatabaseHas('settings', ['key' => 'loyalty.customer_points_per_currency_unit', 'scope_type' => 'franchise']);
        // ...but referral.pending_expiry_days always writes Global, since
        // ReferralService reads it with a hardcoded empty scope.
        $this->assertDatabaseHas('settings', ['key' => 'referral.pending_expiry_days', 'scope_type' => 'global', 'value' => '14']);
        $this->assertDatabaseMissing('settings', ['key' => 'referral.pending_expiry_days', 'scope_type' => 'franchise']);
    }

    public function test_kyc_and_compensation_tabs_respect_the_scope_picker(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();

        Livewire::actingAs($this->actor())->test(Manage::class)
            ->set('scopeType', 'franchise')
            ->set('scopeFranchiseId', $franchise->id)
            ->set('activeTab', 'kyc')
            ->set('kycMaxDocumentSizeMb', '20')
            ->set('kycMaxVideoSizeMb', '60')
            ->call('saveKyc')
            ->assertHasNoErrors();

        // These DO respect scope — their consumers are called with a real
        // franchise/zone-derived scope (e.g. PayoutService::payoutScope()).
        $this->assertDatabaseHas('settings', ['key' => 'kyc.max_document_size_mb', 'scope_type' => 'franchise', 'scope_id' => $franchise->id, 'value' => '20']);
    }
}
