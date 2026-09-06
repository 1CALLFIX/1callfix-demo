<?php

namespace Tests\Feature\Payments;

use App\Livewire\Provider\RequestPayout;
use App\Models\PaymentAccount;
use App\Models\Payout;
use App\Models\Setting;
use App\Services\PaymentAccountService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * The deferred "Next planned step" from the Payment Accounts session
 * (App\Livewire\Provider\PaymentAccounts): a provider picks one of their own
 * VERIFIED payment accounts, enters an amount, and this calls the
 * already-built PayoutService::request() — no new payout logic, only the
 * self-service wiring. PayoutService's own limits/KYC/ownership behavior is
 * already covered by PaymentAccountEngineTest/KycEngineTest; this file only
 * covers what's new here: the verified-accounts-only restriction (stricter
 * than Payouts\Manage's admin screen, an explicit user decision) and the
 * component's own read paths (wallet balance, history, empty state).
 */
class ProviderRequestPayoutTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private function makeProvider()
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();

        return $this->makeProviderIn($franchise, $zone);
    }

    private function verifiedAccount($provider, string $upiId = 'provider@upi'): PaymentAccount
    {
        $service = app(PaymentAccountService::class);
        $service->create($provider->user, ['account_type' => 'upi', 'upi_id' => $upiId]);
        $account = PaymentAccount::where('user_id', $provider->user_id)->where('upi_id', $upiId)->sole();
        $service->verify($account);

        return $account->fresh();
    }

    public function test_provider_with_no_verified_account_sees_empty_state_and_cannot_request(): void
    {
        $provider = $this->makeProvider();
        app(WalletService::class)->credit($provider->user, 500, 'seed');

        Livewire::actingAs($provider->user)->test(RequestPayout::class)
            ->assertOk()
            ->assertSee("You don't have a verified payment account yet", false)
            ->call('request')
            ->assertHasErrors(['paymentAccountId']);

        $this->assertSame(0, Payout::count());
    }

    public function test_unverified_account_is_not_offered_or_accepted(): void
    {
        $provider = $this->makeProvider();
        app(WalletService::class)->credit($provider->user, 500, 'seed');
        app(PaymentAccountService::class)->create($provider->user, ['account_type' => 'upi', 'upi_id' => 'unverified@upi']);
        $unverified = PaymentAccount::where('user_id', $provider->user_id)->sole();

        Livewire::actingAs($provider->user)->test(RequestPayout::class)
            ->assertDontSee('unverified@upi')
            ->set('paymentAccountId', $unverified->id)
            ->set('amount', '100')
            ->call('request');

        $this->assertSame(0, Payout::count());
    }

    public function test_provider_can_request_a_payout_against_their_own_verified_account(): void
    {
        $provider = $this->makeProvider();
        app(WalletService::class)->credit($provider->user, 500, 'seed');
        $account = $this->verifiedAccount($provider);

        Livewire::actingAs($provider->user)->test(RequestPayout::class)
            ->set('amount', '200')
            ->call('request')
            ->assertSet('flashType', 'success');

        $payout = Payout::sole();
        $this->assertSame('provider', $payout->payee_type);
        $this->assertSame($provider->id, $payout->payee_id);
        $this->assertSame($account->id, $payout->payment_account_id);
        $this->assertSame('pending', $payout->status);
        $this->assertEquals(200.0, (float) $payout->amount);
        $this->assertEquals(300.0, app(WalletService::class)->balance($provider->user->fresh()));
    }

    public function test_defaults_to_the_providers_default_verified_account(): void
    {
        $provider = $this->makeProvider();
        app(WalletService::class)->credit($provider->user, 500, 'seed');
        $this->verifiedAccount($provider, 'first@upi'); // becomes default (first account)
        $second = $this->verifiedAccount($provider, 'second@upi');

        // Ensure "first" is genuinely the one flagged default.
        $default = PaymentAccount::where('user_id', $provider->user_id)->where('is_default', true)->sole();
        $this->assertSame('first@upi', $default->upi_id);

        $component = Livewire::actingAs($provider->user)->test(RequestPayout::class);
        $this->assertSame($default->id, $component->get('paymentAccountId'));
        $this->assertNotSame($second->id, $component->get('paymentAccountId'));
    }

    public function test_cannot_request_more_than_wallet_balance(): void
    {
        $provider = $this->makeProvider();
        app(WalletService::class)->credit($provider->user, 50, 'seed');
        $this->verifiedAccount($provider);

        Livewire::actingAs($provider->user)->test(RequestPayout::class)
            ->set('amount', '999')
            ->call('request')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, Payout::count());
    }

    public function test_amount_below_configured_minimum_is_rejected(): void
    {
        $provider = $this->makeProvider();
        app(WalletService::class)->credit($provider->user, 500, 'seed');
        $this->verifiedAccount($provider);

        Setting::create(['scope_type' => 'global', 'scope_id' => null, 'key' => 'wallet.provider_min_payout_amount', 'value' => '100']);

        Livewire::actingAs($provider->user)->test(RequestPayout::class)
            ->set('amount', '50')
            ->call('request')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, Payout::count());
    }

    public function test_kyc_restricted_provider_sees_banner_and_cannot_submit(): void
    {
        $provider = $this->makeProvider();
        $provider->update(['kyc_status' => 'pending', 'kyc_deadline_at' => now()->subDay()]);
        app(WalletService::class)->credit($provider->user, 500, 'seed');
        $this->verifiedAccount($provider);

        Livewire::actingAs($provider->user)->test(RequestPayout::class)
            ->assertSee('Withdrawals are temporarily restricted')
            ->set('amount', '100')
            ->call('request')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, Payout::count());
    }

    public function test_provider_cannot_request_against_another_providers_verified_account(): void
    {
        $owner = $this->makeProvider();
        $stranger = $this->makeProvider();
        app(WalletService::class)->credit($stranger->user, 500, 'seed');
        $ownerAccount = $this->verifiedAccount($owner);

        Livewire::actingAs($stranger->user)->test(RequestPayout::class)
            ->set('paymentAccountId', $ownerAccount->id)
            ->set('amount', '100')
            ->call('request')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, Payout::count());
    }

    public function test_payout_history_shows_past_requests_with_status(): void
    {
        $provider = $this->makeProvider();
        app(WalletService::class)->credit($provider->user, 500, 'seed');
        $account = $this->verifiedAccount($provider);

        Livewire::actingAs($provider->user)->test(RequestPayout::class)
            ->set('amount', '150')
            ->call('request');

        Livewire::actingAs($provider->user)->test(RequestPayout::class)
            ->assertSee('150.00')
            ->assertSee('Pending');
    }

    /** A provider carrying one outstanding cash-commission receivable of $owed. */
    private function providerOwing(float $owed, float $walletSeed)
    {
        $scenario = $this->makeBookingScenario();
        $provider = $scenario['provider'];
        app(WalletService::class)->credit($provider->user, $walletSeed, 'seed');
        $this->verifiedAccount($provider);

        $commission = \App\Models\Commission::create([
            'booking_id' => $scenario['booking']->id,
            'provider_commission' => 0, 'franchise_commission' => 0, 'platform_commission' => $owed,
        ]);
        \App\Models\ProviderCommissionReceivable::create([
            'provider_id' => $provider->id,
            'booking_id' => $scenario['booking']->id,
            'commission_id' => $commission->id,
            'platform_portion' => $owed, 'franchise_portion' => 0,
            'amount_owed' => $owed, 'amount_settled' => 0, 'status' => 'outstanding',
        ]);

        return $provider;
    }

    public function test_screen_shows_withdrawable_balance_net_of_outstanding_cash_commission(): void
    {
        $provider = $this->providerOwing(owed: 120, walletSeed: 500);

        Livewire::actingAs($provider->user)->test(RequestPayout::class)
            ->assertSee('Withdrawable now', false)
            ->assertSee('380.00')   // 500 - 120
            ->assertSee('cash commission owed', false);
    }

    public function test_request_is_blocked_when_cash_commission_debt_exceeds_wallet(): void
    {
        $provider = $this->providerOwing(owed: 200, walletSeed: 50);
        $account = PaymentAccount::where('user_id', $provider->user_id)->sole();

        Livewire::actingAs($provider->user)->test(RequestPayout::class)
            ->set('paymentAccountId', $account->id)
            ->set('amount', '10')
            ->call('request')
            ->assertSet('flashType', 'error');

        // The ₹50 wallet was swept toward the debt; no payout row.
        $this->assertSame(0, Payout::count());
        $this->assertEquals(0.0, app(WalletService::class)->balance($provider->user));
        $this->assertEquals(
            150.0,
            app(\App\Services\PayoutService::class)->outstandingCashCommission($provider->id)
        );
    }
}
