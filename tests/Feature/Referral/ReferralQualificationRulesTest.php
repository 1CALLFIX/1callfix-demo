<?php

namespace Tests\Feature\Referral;

use App\Models\Booking;
use App\Models\Referral;
use App\Models\Setting;
use App\Services\ReferralBalanceService;
use App\Services\ReferralService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Owner decision 2026-10-17 — which completed jobs make a referral count is Super Admin controlled:
 *   referral.qualifying_payment  unset / 'online_or_cash' = cash or online; 'online' = online only.
 *   referral.min_job_value       blank = no minimum.
 * The reward goes to the separate referral balance, never to the wallet.
 */
class ReferralQualificationRulesTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;
    use \Tests\Feature\Support\WithLegacyReferral;

    /** @return array{0: \App\Models\User, 1: Booking} */
    private function scenario(string $method, string $paymentStatus, float $price = 500): array
    {
        $referrer = $this->makeCustomer();
        $s = $this->makeBookingScenario('completed');
        $s['booking']->update(['payment_method' => $method, 'payment_status' => $paymentStatus, 'price_quoted' => $price, 'price_final' => $price]);
        $s['customer']->forceFill(['referred_by' => $referrer->id])->save();
        Referral::create(['referrer_id' => $referrer->id, 'referred_id' => $s['customer']->id, 'status' => 'pending']);

        return [$referrer, $s['booking']->fresh()];
    }

    private function referralBalance($user): float
    {
        return app(ReferralBalanceService::class)->available($user);
    }

    #[DataProvider('cashMethods')]
    public function test_by_default_a_completed_cash_job_counts_and_pays_into_the_referral_balance(string $method, string $status): void
    {
        [$referrer, $booking] = $this->scenario($method, $status);

        $this->assertSame('rewarded', app(ReferralService::class)->qualifyFromCompletedBooking($booking)?->status);
        $this->assertEqualsWithDelta(50.0, $this->referralBalance($referrer), 0.001);
        $this->assertSame(0.0, (float) app(WalletService::class)->balance($referrer), 'referral money never goes to the wallet');
    }

    public static function cashMethods(): array
    {
        return ['cash' => ['cash', 'pending'], 'cash+online' => ['cash+online', 'pending']];
    }

    public function test_explicit_online_or_cash_setting_behaves_like_the_default(): void
    {
        Setting::set('referral.qualifying_payment', 'online_or_cash');
        [$referrer, $booking] = $this->scenario('cash', 'pending');

        $this->assertSame('rewarded', app(ReferralService::class)->qualifyFromCompletedBooking($booking)?->status);
    }

    public function test_online_only_setting_refuses_a_cash_job(): void
    {
        Setting::set('referral.qualifying_payment', 'online');
        [$referrer, $booking] = $this->scenario('cash', 'pending');

        $this->assertNull(app(ReferralService::class)->qualifyFromCompletedBooking($booking));
        $referral = Referral::where('referrer_id', $referrer->id)->firstOrFail();
        $this->assertSame('pending', $referral->status);
        $this->assertSame(ReferralService::REASON_NOT_ONLINE, $referral->no_reward_reason);
        $this->assertSame(0.0, $this->referralBalance($referrer));
    }

    public function test_an_online_job_still_needs_its_payment_captured(): void
    {
        [$referrer, $booking] = $this->scenario('online', 'pending');

        $this->assertNull(app(ReferralService::class)->qualifyFromCompletedBooking($booking));
        $this->assertSame(ReferralService::REASON_NOT_PAID, Referral::where('referrer_id', $referrer->id)->value('no_reward_reason'));
    }

    public function test_minimum_job_value_blocks_a_smaller_job_and_allows_an_equal_one(): void
    {
        Setting::set('referral.min_job_value', '300');

        [$small] = $this->scenario('online', 'paid', 299);
        $smallBooking = Booking::where('customer_id', Referral::where('referrer_id', $small->id)->value('referred_id'))->firstOrFail();
        $this->assertNull(app(ReferralService::class)->qualifyFromCompletedBooking($smallBooking));
        $this->assertSame(ReferralService::REASON_BELOW_MIN, Referral::where('referrer_id', $small->id)->value('no_reward_reason'));

        [$equal, $equalBooking] = $this->scenario('online', 'paid', 300);
        $this->assertSame('rewarded', app(ReferralService::class)->qualifyFromCompletedBooking($equalBooking)?->status);
    }

    public function test_blank_minimum_means_no_minimum(): void
    {
        Setting::set('referral.min_job_value', '');
        [, $booking] = $this->scenario('online', 'paid', 1);

        $this->assertSame('rewarded', app(ReferralService::class)->qualifyFromCompletedBooking($booking)?->status);
    }

    public function test_fraud_flag_takes_the_reward_back_from_the_referral_balance(): void
    {
        [$referrer, $booking] = $this->scenario('online', 'paid');
        $referral = app(ReferralService::class)->qualifyFromCompletedBooking($booking);

        app(ReferralService::class)->flagAsFraud($referral, $this->makeSuperAdmin(), 'farming');

        $this->assertSame(0.0, $this->referralBalance($referrer));
        $this->assertSame('fraud_flagged', $referral->fresh()->status);
    }

    public function test_super_admin_saves_the_referral_rules_audited_and_validated(): void
    {
        $admin = $this->makeSuperAdmin();

        \Livewire\Livewire::actingAs($admin)->test(\App\Livewire\EarningsControl\Manage::class)
            ->call('setTab', 'referrals')
            ->assertSee('Referral rules')
            ->set('ruleInputs.referral__balance_max_use_percent', '150')
            ->call('saveReferralRules')
            ->assertHasErrors('ruleInputs.referral__balance_max_use_percent')
            ->set('ruleInputs.referral__qualifying_payment', 'online')
            ->set('ruleInputs.referral__min_job_value', '299')
            ->set('ruleInputs.referral__balance_max_use_percent', '20')
            ->set('ruleInputs.referral__balance_expiry_days', '')
            ->call('saveReferralRules')
            ->assertHasNoErrors();

        $this->assertSame('online', Setting::get('referral.qualifying_payment'));
        $this->assertSame('299', Setting::get('referral.min_job_value'));
        $this->assertSame('20', Setting::get('referral.balance_max_use_percent'));
        $this->assertNull(Setting::get('referral.balance_expiry_days'));
        $keys = \App\Models\ActivityLog::where('subject_type', 'setting')->get()->map(fn ($l) => $l->properties['key'] ?? null)->all();
        $this->assertContains('referral.balance_max_use_percent', $keys);
    }

    public function test_customer_tab_and_api_show_the_referral_balance(): void
    {
        foreach (['earnings.enabled' => '1', 'earnings.referral_tab' => '1', 'referral.balance_max_use_percent' => '20'] as $k => $v) {
            Setting::set($k, $v);
        }
        [$referrer, $booking] = $this->scenario('cash', 'pending');
        app(ReferralService::class)->qualifyFromCompletedBooking($booking);

        $this->actingAs($referrer)->get(route('customer.earnings.referrals'))
            ->assertOk()->assertSee('Referral balance')->assertSee('50.00')->assertSee('up to 20% of a booking');
        $this->actingAs($referrer, 'sanctum')->getJson('/api/referrals/mine')
            ->assertOk()->assertJsonPath('referral_balance', 50);
    }

    private function makeSuperAdmin(): \App\Models\User
    {
        return \App\Models\User::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Admin', 'phone' => '8'.fake()->unique()->numerify('#########'),
            'role' => 'super_admin', 'status' => 'active',
        ]);
    }
}
