<?php

namespace Tests\Feature\Referral;

use App\Livewire\EarningsControl\Manage;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\LoyaltyPoint;
use App\Models\Referral;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Earnings\WalletFreezeService;
use App\Services\LoyaltyService;
use App\Services\ReferralService;
use App\Services\Referrals\ReferralCapture;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\EarningsSettingsFixtureValues;
use Tests\TestCase;

/**
 * REF 1CF-PROMPT-20260925-EARN4 — Stage 3: Super Admin release / reject,
 * referral block, referee reward (R6, R7, R8).
 */
class ReferralControlsTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        EarningsSettingsFixtureValues::referral();   // referrer: wallet ₹50, cap 1000
        EarningsSettingsFixtureValues::loyalty();    // points expiry configured
        $this->admin = $this->makeSuperAdmin();
    }

    /** A referral whose referee has one completed booking. */
    private function scenario(string $status = 'pending'): array
    {
        ['booking' => $booking, 'customer' => $referee] = $this->makeBookingScenario('completed');
        $booking->update(['payment_method' => 'online', 'payment_status' => 'paid']); // D8: only an online-paid job qualifies
        $booking = $booking->fresh();
        $referrer = $this->makeCustomer();
        $referral = Referral::create(['referrer_id' => $referrer->id, 'referred_id' => $referee->id, 'status' => $status]);

        return [$referral, $referrer, $referee, $booking];
    }

    private function walletRefee(): void
    {
        Setting::set('referral.referee_reward_type', 'wallet');
        Setting::set('referral.referee_reward_amount', '30');
    }

    private function balance(User $u): float
    {
        return (float) app(WalletService::class)->balance($u);
    }

    // ───────────────────────── release / reject ─────────────────────────

    public function test_releasing_a_held_referral_that_qualifies_pays_both_rewards_exactly_once(): void
    {
        $this->walletRefee();
        [$referral, $referrer, $referee] = $this->scenario('held_review');

        $first = app(ReferralService::class)->release($referral, $this->admin, 'checked: family members, genuine');
        $this->assertSame('rewarded', $first['outcome']);
        $this->assertSame('rewarded', $referral->fresh()->status);
        $this->assertEqualsWithDelta(50.0, $this->balance($referrer), 0.001);
        $this->assertEqualsWithDelta(30.0, $this->balance($referee), 0.001);

        $second = app(ReferralService::class)->release($referral->fresh(), $this->admin, 'clicked again');
        $this->assertSame('nothing_to_release', $second['outcome']);
        $this->assertEqualsWithDelta(50.0, $this->balance($referrer), 0.001);
        $this->assertEqualsWithDelta(30.0, $this->balance($referee), 0.001);
        $this->assertSame(1, WalletTransaction::where('ref', "referral:{$referral->id}:reward")->count());
        $this->assertSame(1, WalletTransaction::where('ref', "referral:{$referral->id}:referee-reward")->count());

        $fresh = $referral->fresh();
        $this->assertSame($this->admin->id, $fresh->reviewed_by);
        $this->assertNotNull($fresh->referee_rewarded_at);
        $this->assertEqualsWithDelta(30.0, (float) $fresh->referee_reward_amount, 0.001);
    }

    public function test_released_referral_without_a_completed_booking_waits_and_then_pays_automatically(): void
    {
        $referee = $this->makeCustomer();
        $referral = Referral::create(['referrer_id' => $this->makeCustomer()->id, 'referred_id' => $referee->id, 'status' => 'held_review']);

        $this->assertSame('released_awaiting_first_completed_booking', app(ReferralService::class)->release($referral, $this->admin, 'ok')['outcome']);
        $this->assertSame('pending', $referral->fresh()->status);
    }

    /** D8 thumb rule: a Super Admin release cannot pay on a cash job, but a later online-paid job still qualifies. */
    public function test_release_never_pays_on_a_cash_job_but_uses_a_later_online_paid_job(): void
    {
        [$referral, $referrer, , $booking] = $this->scenario('held_review');
        $booking->update(['payment_method' => 'cash', 'payment_status' => 'paid']);

        $first = app(ReferralService::class)->release($referral, $this->admin, 'genuine');
        $this->assertSame('released_awaiting_first_completed_booking', $first['outcome']);
        $this->assertSame('pending', $referral->fresh()->status);
        $this->assertEqualsWithDelta(0.0, $this->balance($referrer), 0.001);

        $online = $booking->replicate();
        $online->forceFill(['code' => $booking->code.'-B', 'payment_method' => 'online', 'payment_status' => 'paid'])->save();

        $second = app(ReferralService::class)->release($referral->fresh(), $this->admin, 'online job done');
        $this->assertSame('rewarded', $second['outcome']);
        $this->assertSame($online->id, $referral->fresh()->qualifying_booking_id);
        $this->assertEqualsWithDelta(50.0, $this->balance($referrer), 0.001);
    }

    public function test_held_referral_is_not_paid_by_the_automatic_trigger(): void
    {
        [$referral, $referrer, , $booking] = $this->scenario('held_review');

        $this->assertNull(app(ReferralService::class)->qualifyFromCompletedBooking($booking));
        $this->assertSame(0.0, $this->balance($referrer));
    }

    public function test_reject_is_final(): void
    {
        [$referral, $referrer, , $booking] = $this->scenario('held_review');

        app(ReferralService::class)->reject($referral, $this->admin, 'same household farming');
        $this->assertSame('rejected', $referral->fresh()->status);

        $this->assertNull(app(ReferralService::class)->qualifyFromCompletedBooking($booking));
        try {
            app(ReferralService::class)->release($referral->fresh(), $this->admin, 'changed my mind');
            $this->fail('released a rejected referral');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('rejected referral cannot be released', $e->getMessage());
        }
        try {
            app(ReferralService::class)->reject($referral->fresh(), $this->admin, 'again');
            $this->fail('re-rejected');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0.0, $this->balance($referrer));
    }

    public function test_release_and_reject_need_a_reason(): void
    {
        [$referral] = $this->scenario('held_review');

        $this->expectExceptionMessage('A reason is required.');
        app(ReferralService::class)->release($referral, $this->admin, '   ');
    }

    public function test_frozen_referrer_reward_is_released_after_unfreeze(): void
    {
        [$referral, $referrer, , $booking] = $this->scenario();
        app(WalletFreezeService::class)->freeze($this->admin, $referrer, 'review');

        $this->assertNull(app(ReferralService::class)->qualifyFromCompletedBooking($booking));
        $this->assertSame('referrer_wallet_frozen', $referral->fresh()->no_reward_reason);
        $this->assertSame('pending', $referral->fresh()->status);

        app(WalletFreezeService::class)->unfreeze($this->admin, $referrer, 'cleared');
        $this->assertSame('rewarded', app(ReferralService::class)->release($referral->fresh(), $this->admin, 'withheld while frozen')['outcome']);

        $this->assertEqualsWithDelta(50.0, $this->balance($referrer), 0.001);
        $this->assertNull($referral->fresh()->no_reward_reason);
    }

    // ───────────────────────── referee reward (R7) ─────────────────────────

    public function test_referee_wallet_reward_on_the_same_trigger(): void
    {
        $this->walletRefee();
        [$referral, $referrer, $referee, $booking] = $this->scenario();

        app(ReferralService::class)->qualifyFromCompletedBooking($booking);

        $this->assertEqualsWithDelta(50.0, $this->balance($referrer), 0.001);
        $this->assertEqualsWithDelta(30.0, $this->balance($referee), 0.001);
        $this->assertSame('Referral welcome reward', \App\Support\WalletSourceLabel::labelFor("referral:{$referral->id}:referee-reward"));
    }

    public function test_referee_points_reward(): void
    {
        Setting::set('referral.referee_reward_type', 'points');
        Setting::set('referral.referee_reward_points', '120');
        [$referral, , $referee, $booking] = $this->scenario();

        app(ReferralService::class)->qualifyFromCompletedBooking($booking);

        $this->assertSame(120, app(LoyaltyService::class)->balance($referee));
        $this->assertSame(120, (int) $referral->fresh()->referee_reward_points);
        $this->assertSame(1, LoyaltyPoint::where('ref', "referral:{$referral->id}:referee-points-reward")->count());
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function unsetRefereeConfigs(): array
    {
        return ['type unset' => [null, '30'], 'amount unset' => ['wallet', null]];
    }

    #[DataProvider('unsetRefereeConfigs')]
    public function test_unset_referee_reward_pays_nothing_to_the_referee(?string $type, ?string $amount): void
    {
        if ($type) {
            Setting::set('referral.referee_reward_type', $type);
        }
        if ($amount) {
            Setting::set('referral.referee_reward_amount', $amount);
        }
        [$referral, $referrer, $referee, $booking] = $this->scenario();

        app(ReferralService::class)->qualifyFromCompletedBooking($booking);

        $this->assertEqualsWithDelta(50.0, $this->balance($referrer), 0.001);
        $this->assertSame(0.0, $this->balance($referee));
        $this->assertNull($referral->fresh()->referee_rewarded_at);
    }

    public function test_frozen_referee_is_withheld_then_released(): void
    {
        $this->walletRefee();
        [$referral, $referrer, $referee, $booking] = $this->scenario();
        app(WalletFreezeService::class)->freeze($this->admin, $referee, 'kyc');

        app(ReferralService::class)->qualifyFromCompletedBooking($booking);
        $this->assertSame('rewarded', $referral->fresh()->status, 'referrer still paid');
        $this->assertSame(0.0, $this->balance($referee));
        $this->assertSame('referee_wallet_frozen', $referral->fresh()->no_reward_reason);

        app(WalletFreezeService::class)->unfreeze($this->admin, $referee, 'ok');
        $this->assertSame('referee_reward_issued', app(ReferralService::class)->release($referral->fresh(), $this->admin, 'withheld while frozen')['outcome']);
        $this->assertEqualsWithDelta(30.0, $this->balance($referee), 0.001);
        $this->assertEqualsWithDelta(50.0, $this->balance($referrer), 0.001, 'referrer never paid twice');
    }

    public function test_referrer_at_cap_pays_neither_side(): void
    {
        $this->walletRefee();
        Setting::set('referral.max_per_customer', '0');
        [$referral, $referrer, $referee, $booking] = $this->scenario();

        app(ReferralService::class)->qualifyFromCompletedBooking($booking);

        $this->assertSame(0.0, $this->balance($referrer));
        $this->assertSame(0.0, $this->balance($referee));
        $this->assertSame('referrer_cap_reached', $referral->fresh()->no_reward_reason);
    }

    public function test_fraud_flag_claws_back_the_referee_wallet_reward(): void
    {
        $this->walletRefee();
        [$referral, , $referee, $booking] = $this->scenario();
        app(ReferralService::class)->qualifyFromCompletedBooking($booking);

        $flagged = app(ReferralService::class)->flagAsFraud($referral->fresh(), $this->admin, 'farming');

        $this->assertSame(0.0, $this->balance($referee));
        $this->assertSame(1, WalletTransaction::where('ref', "referral:{$referral->id}:referee-clawback")->count());
        $this->assertStringContainsString('Referee reward of 30', $flagged->reversal_note);
    }

    public function test_fraud_flag_points_clawback_never_pushes_the_referee_negative(): void
    {
        Setting::set('referral.referee_reward_type', 'points');
        Setting::set('referral.referee_reward_points', '150');
        Setting::set('loyalty.min_redemption_points', '1');
        [$referral, , $referee, $booking] = $this->scenario();
        app(ReferralService::class)->qualifyFromCompletedBooking($booking);
        app(LoyaltyService::class)->redeem($referee, 100); // only 50 left

        $flagged = app(ReferralService::class)->flagAsFraud($referral->fresh(), $this->admin, 'farming');

        $this->assertSame(0, app(LoyaltyService::class)->balance($referee));
        $this->assertSame(-50, (int) LoyaltyPoint::where('ref', "referral:{$referral->id}:referee-points-clawback")->value('points'));
        $this->assertStringContainsString('shortfall 100', $flagged->reversal_note);
    }

    // ───────────────────────── block (R8) ─────────────────────────

    public function test_blocked_user_code_is_rejected_and_pending_referrals_do_not_reward(): void
    {
        [$referral, $referrer, , $booking] = $this->scenario();

        app(ReferralService::class)->blockUser($this->admin, $referrer, 'serial farming');

        $this->assertNull(app(ReferralCapture::class)->referrerFor($referrer->referral_code));
        $this->assertNull(app(ReferralService::class)->qualifyFromCompletedBooking($booking));
        $this->assertSame('referrer_blocked', $referral->fresh()->no_reward_reason);
        $this->assertSame(0.0, $this->balance($referrer));

        app(ReferralService::class)->unblockUser($this->admin, $referrer->fresh(), 'appeal accepted');
        $this->assertNotNull(app(ReferralCapture::class)->referrerFor($referrer->referral_code));
        $this->assertSame('rewarded', app(ReferralService::class)->release($referral->fresh(), $this->admin, 'unblocked')['outcome']);
    }

    public function test_block_and_unblock_need_a_reason(): void
    {
        $this->expectExceptionMessage('A reason is required.');
        app(ReferralService::class)->blockUser($this->admin, $this->makeCustomer(), '');
    }

    // ───────────────────────── audit ─────────────────────────

    public function test_every_action_writes_an_audit_entry(): void
    {
        [$referral, $referrer] = $this->scenario('held_review');
        [$other] = $this->scenario('held_review');
        $svc = app(ReferralService::class);

        $svc->release($referral, $this->admin, 'r1');
        $svc->reject($other, $this->admin, 'r2');
        $svc->blockUser($this->admin, $referrer, 'r3');
        $svc->unblockUser($this->admin, $referrer->fresh(), 'r4');

        $reasons = ActivityLog::where('causer_id', $this->admin->id)->get()->map(fn ($l) => $l->properties['reason'] ?? null)->all();
        foreach (['r1', 'r2', 'r3', 'r4'] as $r) {
            $this->assertContains($r, $reasons);
        }
    }

    // ───────────────────────── admin screen + 403 ─────────────────────────

    public function test_admin_screen_release_reject_block_and_settings(): void
    {
        $this->walletRefee();
        [$referral, $referrer] = $this->scenario('held_review');

        Livewire::actingAs($this->admin)->test(Manage::class)
            ->call('setTab', 'referrals')
            ->assertSee('held_review')
            ->call('startReview', $referral->id)
            ->set('reviewReason', 'verified by phone call')
            ->call('releaseReferral')
            ->assertSet('flashType', 'success')
            ->call('startBlock', $referrer->id)
            ->set('blockReason', 'abuse')
            ->call('blockReferrals')
            ->call('setProtection', 'referral.hold_same_device', '0')
            ->set('refereeInputs.referral__referee_reward_points', '10')
            ->call('saveRefereeReward')
            ->assertHasNoErrors();

        $this->assertSame('rewarded', $referral->fresh()->status);
        $this->assertNotNull($referrer->fresh()->referral_blocked_at);
        $this->assertSame('0', Setting::get('referral.hold_same_device'));
        $this->assertSame('10', Setting::get('referral.referee_reward_points'));
        $keys = ActivityLog::where('subject_type', 'setting')->get()->map(fn ($l) => $l->properties['key'])->all();
        $this->assertContains('referral.hold_same_device', $keys);
    }

    public function test_admin_list_shows_signup_ip_to_super_admin(): void
    {
        [$referral] = $this->scenario();
        $referral->forceFill(['signup_ip' => '203.0.113.9', 'signup_device_id' => 'dev-admin-see'])->save();

        Livewire::actingAs($this->admin)->test(Manage::class)->call('setTab', 'referrals')
            ->assertSee('203.0.113.9')->assertSee('dev-admin-see');
    }

    public function test_monitoring_counts_and_held_list(): void
    {
        $this->scenario('held_review');

        Livewire::actingAs($this->admin)->test(Manage::class)->call('setTab', 'monitoring')
            ->assertSee('Referrals by status')
            ->assertSee('Referrals held for review')
            ->assertViewHas('referralCounts', fn ($c) => $c['held_review'] === 1);
    }

    /** @return array<string, array{0: string, 1: array}> */
    public static function policyActions(): array
    {
        return [
            'start review' => ['startReview', [1]],
            'release' => ['releaseReferral', []],
            'reject' => ['rejectReferral', []],
            'start block' => ['startBlock', [1]],
            'block' => ['blockReferrals', []],
            'unblock' => ['unblockReferrals', []],
            'protection' => ['setProtection', ['referral.hold_same_signup_ip', '0']],
            'referee reward' => ['saveRefereeReward', []],
        ];
    }

    #[DataProvider('policyActions')]
    public function test_non_super_admin_gets_403_on_every_referral_control(string $method, array $args): void
    {
        [$referral, $referrer] = $this->scenario('held_review');
        $component = Livewire::actingAs($this->admin)->test(Manage::class)
            ->call('setTab', 'referrals')
            ->call('startReview', $referral->id)->set('reviewReason', 'x')
            ->call('startBlock', $referrer->id)->set('blockReason', 'x');

        $this->actingAs($this->makeUserWithPermission('settings.manage', 'global'));
        $component->call($method, ...$args)->assertForbidden();

        $this->assertSame('held_review', $referral->fresh()->status);
        $this->assertNull($referrer->fresh()->referral_blocked_at);
        $this->assertNull(Setting::get('referral.hold_same_signup_ip'));
    }

    public function test_service_actions_refuse_a_non_super_admin(): void
    {
        [$referral, $referrer] = $this->scenario('held_review');
        $staff = $this->makeUserWithPermission('loyalty.manage', 'global');

        foreach ([
            fn () => app(ReferralService::class)->release($referral, $staff, 'x'),
            fn () => app(ReferralService::class)->reject($referral, $staff, 'x'),
            fn () => app(ReferralService::class)->blockUser($staff, $referrer, 'x'),
            fn () => app(ReferralService::class)->unblockUser($staff, $referrer, 'x'),
        ] as $call) {
            try {
                $call();
                $this->fail('non-super-admin allowed');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }
}
