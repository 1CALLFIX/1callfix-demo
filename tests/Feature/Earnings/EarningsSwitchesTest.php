<?php

namespace Tests\Feature\Earnings;

use App\Actions\AcceptBookingAction;
use App\Actions\CompleteBookingAction;
use App\Models\Booking;
use App\Models\LoyaltyPoint;
use App\Models\Referral;
use App\Models\Setting;
use App\Models\Wallet;
use App\Services\LoyaltyService;
use App\Services\ReferralService;
use App\Services\WalletService;
use App\Services\WalletTopUpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\EarningsSettingsFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — Stage 2. One test per switch / removed
 * fallback: ON changes behaviour, '0' blocks it, UNSET (null) behaves
 * exactly like OFF.
 */
class EarningsSwitchesTest extends TestCase
{
    use BookingFixtureHelpers;
    use EarningsSettingsFixture;
    use RefreshDatabase;

    /** @return array<string, array{0: ?string}> */
    public static function offStates(): array
    {
        return ['explicit off' => ['0'], 'unset (null)' => [null]];
    }

    private function setOrClear(string $key, ?string $value): void
    {
        $value === null ? Setting::clear($key, 'global', null) : Setting::set($key, $value);
    }

    // ───────────────────── wallet.topup_enabled + limits ─────────────────────

    private function fakeGateway(): void
    {
        config([
            'services.razorpay.key_id' => 'rzp_test_fakekeyid123',
            'services.razorpay.key_secret' => 'fake-test-key-secret-never-real',
            'services.razorpay.webhook_secret' => 'fake-test-webhook-secret-never-real',
        ]);
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_x', 'amount' => 50000, 'currency' => 'INR'], 200)]);
    }

    public function test_topup_on_creates_an_order(): void
    {
        $this->fakeGateway();
        $this->configureLegacyWalletTopUp();

        $order = app(WalletTopUpService::class)->requestTopUp($this->makeCustomer(), 500);

        $this->assertSame('order_x', $order['razorpay_order_id']);
    }

    #[DataProvider('offStates')]
    public function test_topup_off_or_unset_is_unavailable(?string $state): void
    {
        $this->fakeGateway();
        $this->configureLegacyWalletTopUp();
        $this->setOrClear('wallet.topup_enabled', $state);

        $this->expectExceptionMessage('Top-up is currently unavailable');
        app(WalletTopUpService::class)->requestTopUp($this->makeCustomer(), 500);
    }

    /** @return array<string, array{0: string}> */
    public static function topupLimitKeys(): array
    {
        return [
            'min' => ['wallet.customer_min_topup'], 'max' => ['wallet.customer_max_topup'],
            'max balance' => ['wallet.customer_max_balance'], 'daily' => ['wallet.customer_daily_topup_limit'],
            'monthly' => ['wallet.customer_monthly_topup_limit'],
        ];
    }

    #[DataProvider('topupLimitKeys')]
    public function test_an_unset_topup_limit_keeps_topup_off_no_hidden_default(string $key): void
    {
        $this->fakeGateway();
        $this->configureLegacyWalletTopUp();
        Setting::clear($key, 'global', null);

        $this->expectExceptionMessage('Top-up is currently unavailable');
        app(WalletTopUpService::class)->requestTopUp($this->makeCustomer(), 500);
    }

    public function test_topup_off_answers_the_api_with_a_clear_message(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/wallet/topup', ['amount' => 500])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Top-up is currently unavailable.');
    }

    // ───────────────────── payment.wallet_enabled ─────────────────────

    public function test_wallet_payment_on_pays_from_wallet(): void
    {
        $this->configureWalletPayments();
        ['booking' => $booking, 'customer' => $customer] = $this->walletPayableBooking();

        $this->assertSame('wallet', $this->payBookingFromWallet($booking)->gateway);
    }

    #[DataProvider('offStates')]
    public function test_wallet_payment_off_or_unset_is_refused(?string $state): void
    {
        $this->setOrClear('payment.wallet_enabled', $state);
        ['booking' => $booking] = $this->walletPayableBooking();

        $this->expectExceptionMessage('Wallet payments are not enabled');
        $this->payBookingFromWallet($booking);
    }

    #[DataProvider('offStates')]
    public function test_wallet_is_not_offered_as_a_payment_method_when_off_or_unset(?string $state): void
    {
        $this->setOrClear('payment.wallet_enabled', $state);

        $this->assertArrayNotHasKey('wallet', Setting::enabledPaymentMethods());
    }

    public function test_wallet_is_offered_when_on(): void
    {
        $this->configureWalletPayments();

        $this->assertArrayHasKey('wallet', Setting::enabledPaymentMethods());
    }

    private function walletPayableBooking(): array
    {
        $scenario = $this->makeBookingScenario();
        Wallet::create(['user_id' => $scenario['customer']->id, 'balance' => 5000]);
        $scenario['booking']->update(['payment_method' => 'wallet']);

        return $scenario;
    }

    private function payBookingFromWallet(Booking $booking)
    {
        $method = new \ReflectionMethod(\App\Actions\CreateBookingAction::class, 'payWithWallet');
        $method->setAccessible(true);

        return $method->invoke(app(\App\Actions\CreateBookingAction::class), $booking->fresh());
    }

    // ───────────────────── loyalty.customer_enabled / provider_enabled ─────────────────────

    private function completeBooking(): array
    {
        ['booking' => $booking, 'provider' => $provider, 'customer' => $customer] = $this->makeAssignedBookingScenario();
        $booking->update(['price_quoted' => 1000, 'payment_method' => 'cash']);

        app(CompleteBookingAction::class)->execute($booking->id, $provider, '5678');
        $this->assertSame('completed', $booking->fresh()->status);

        return [$customer, $provider->user];
    }

    public function test_customer_earning_on_awards_points(): void
    {
        $this->configureLegacyLoyalty();

        [$customer] = $this->completeBooking();

        $this->assertSame(10, app(LoyaltyService::class)->balance($customer)); // 1000 × 0.01
    }

    #[DataProvider('offStates')]
    public function test_customer_earning_off_or_unset_awards_nothing(?string $state): void
    {
        $this->configureLegacyLoyalty();
        $this->setOrClear('loyalty.customer_enabled', $state);

        [$customer] = $this->completeBooking();

        $this->assertSame(0, LoyaltyPoint::where('user_id', $customer->id)->count());
    }

    public function test_customer_earning_with_unset_rate_awards_nothing(): void
    {
        $this->configureLegacyLoyalty();
        Setting::clear('loyalty.customer_points_per_currency_unit', 'global', null);

        [$customer] = $this->completeBooking();

        $this->assertSame(0, LoyaltyPoint::where('user_id', $customer->id)->count());
    }

    public function test_customer_earning_with_unset_expiry_policy_awards_nothing(): void
    {
        $this->configureLegacyLoyalty();
        Setting::clear('loyalty.points_expiry_days', 'global', null);

        [$customer] = $this->completeBooking();

        $this->assertSame(0, LoyaltyPoint::where('user_id', $customer->id)->count());
    }

    public function test_expiry_zero_means_never_expires(): void
    {
        $this->configureLegacyLoyalty();
        Setting::set('loyalty.points_expiry_days', '0');

        $row = app(LoyaltyService::class)->earn($this->makeCustomer(), 50, 'promo');

        $this->assertNull($row->expires_at);
    }

    public function test_provider_earning_on_awards_points(): void
    {
        $this->configureLegacyLoyalty();
        $this->configureLegacyProviderLoyalty();

        [, $providerUser] = $this->completeBooking();

        $this->assertSame(5, app(LoyaltyService::class)->balance($providerUser));
    }

    #[DataProvider('offStates')]
    public function test_provider_earning_off_or_unset_awards_nothing(?string $state): void
    {
        $this->configureLegacyLoyalty();
        $this->configureLegacyProviderLoyalty();
        $this->setOrClear('loyalty.provider_enabled', $state);

        [, $providerUser] = $this->completeBooking();

        $this->assertSame(0, LoyaltyPoint::where('user_id', $providerUser->id)->count());
    }

    // ───────────────────── loyalty.redeem_enabled ─────────────────────

    public function test_redeem_on_works(): void
    {
        $this->configureLegacyLoyalty();
        $customer = $this->makeCustomer();
        app(LoyaltyService::class)->earn($customer, 200, 'promo');

        $this->actingAs($customer, 'sanctum')->postJson('/api/loyalty/redeem', ['points' => 100])->assertOk();
    }

    #[DataProvider('offStates')]
    public function test_redeem_off_or_unset_is_refused(?string $state): void
    {
        $this->configureLegacyLoyalty();
        $customer = $this->makeCustomer();
        app(LoyaltyService::class)->earn($customer, 200, 'promo');
        $this->setOrClear('loyalty.redeem_enabled', $state);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/loyalty/redeem', ['points' => 100])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Loyalty redemption is currently unavailable.');

        $this->assertSame(200, app(LoyaltyService::class)->balance($customer));
    }

    /** @return array<string, array{0: string}> */
    public static function redeemPolicyKeys(): array
    {
        return ['rate' => ['loyalty.points_per_rupee_redemption'], 'minimum' => ['loyalty.min_redemption_points']];
    }

    #[DataProvider('redeemPolicyKeys')]
    public function test_redeem_with_an_unset_policy_value_is_refused(string $key): void
    {
        $this->configureLegacyLoyalty();
        $customer = $this->makeCustomer();
        app(LoyaltyService::class)->earn($customer, 200, 'promo');
        Setting::clear($key, 'global', null);

        $this->expectExceptionMessage('Loyalty redemption is currently unavailable');
        app(LoyaltyService::class)->redeem($customer, 100);
    }

    public function test_min_redemption_zero_is_an_explicit_zero(): void
    {
        $this->configureLegacyLoyalty();
        Setting::set('loyalty.min_redemption_points', '0');
        $customer = $this->makeCustomer();
        app(LoyaltyService::class)->earn($customer, 20, 'promo');

        $this->assertSame(10, app(LoyaltyService::class)->redeem($customer, 10)['points_redeemed']);
    }

    // ───────────────────── referral.enabled / max_per_customer ─────────────────────

    private function qualifyingReferral(): array
    {
        ['booking' => $booking, 'customer' => $referred] = $this->makeBookingScenario('completed');
        $referrer = $this->makeCustomer();
        $referral = Referral::create(['referrer_id' => $referrer->id, 'referred_id' => $referred->id, 'status' => 'pending']);

        return [$referral, $referrer, $booking];
    }

    public function test_referral_on_rewards_the_referrer(): void
    {
        $this->configureLegacyReferral();
        [$referral, $referrer, $booking] = $this->qualifyingReferral();

        app(ReferralService::class)->qualifyFromCompletedBooking($booking);

        $this->assertSame('rewarded', $referral->fresh()->status);
        $this->assertEqualsWithDelta(50.0, (float) app(WalletService::class)->balance($referrer), 0.001);
    }

    #[DataProvider('offStates')]
    public function test_referral_off_or_unset_rewards_nothing(?string $state): void
    {
        $this->configureLegacyReferral();
        $this->setOrClear('referral.enabled', $state);
        [$referral, $referrer, $booking] = $this->qualifyingReferral();

        $this->assertNull(app(ReferralService::class)->qualifyFromCompletedBooking($booking));
        $this->assertSame('pending', $referral->fresh()->status);
        $this->assertSame(0.0, (float) app(WalletService::class)->balance($referrer));
    }

    public function test_referral_cap_unset_rewards_nothing(): void
    {
        $this->configureLegacyReferral(null);
        [$referral, , $booking] = $this->qualifyingReferral();

        $this->assertNull(app(ReferralService::class)->qualifyFromCompletedBooking($booking));
        $this->assertSame('pending', $referral->fresh()->status);
    }

    public function test_referral_cap_reached_rewards_nothing_more(): void
    {
        $this->configureLegacyReferral('1');
        [$referral, $referrer, $booking] = $this->qualifyingReferral();
        Referral::create(['referrer_id' => $referrer->id, 'referred_id' => $this->makeCustomer()->id, 'status' => 'rewarded', 'reward_amount' => 50]);

        $this->assertNull(app(ReferralService::class)->qualifyFromCompletedBooking($booking));
        $this->assertSame('pending', $referral->fresh()->status);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function referralRewardKeys(): array
    {
        return ['type' => ['referral.reward_type', 'wallet'], 'wallet amount' => ['referral.reward_amount', 'wallet'], 'points' => ['referral.reward_points', 'points']];
    }

    #[DataProvider('referralRewardKeys')]
    public function test_referral_with_an_unset_reward_value_rewards_nothing(string $key, string $type): void
    {
        $this->configureLegacyReferral();
        $this->configureLegacyLoyalty();
        Setting::set('referral.reward_type', $type);
        Setting::clear($key, 'global', null);
        [$referral, , $booking] = $this->qualifyingReferral();

        $this->assertNull(app(ReferralService::class)->qualifyFromCompletedBooking($booking));
        $this->assertSame('pending', $referral->fresh()->status);
    }
}
