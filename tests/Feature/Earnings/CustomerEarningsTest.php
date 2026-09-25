<?php

namespace Tests\Feature\Earnings;

use App\Livewire\Customer\Earnings\Loyalty;
use App\Livewire\Customer\Earnings\Referrals;
use App\Livewire\Customer\Earnings\Wallet;
use App\Models\Referral;
use App\Models\Setting;
use App\Services\Earnings\WalletFreezeService;
use App\Services\LoyaltyService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\EarningsSettingsFixture;
use Tests\TestCase;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — Stage 4: customer Earnings (Wallet,
 * Loyalty, Referrals tabs), their switches, own-data isolation, and the
 * server-side redemption quote.
 */
class CustomerEarningsTest extends TestCase
{
    use BookingFixtureHelpers;
    use EarningsSettingsFixture;
    use RbacTestHelpers;
    use RefreshDatabase;

    private function allOn(): void
    {
        foreach (['earnings.enabled', 'earnings.wallet_tab', 'earnings.loyalty_tab', 'earnings.referral_tab'] as $key) {
            Setting::set($key, '1');
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function tabs(): array
    {
        return [
            'wallet' => ['customer.earnings.wallet', 'earnings.wallet_tab'],
            'loyalty' => ['customer.earnings.loyalty', 'earnings.loyalty_tab'],
            'referrals' => ['customer.earnings.referrals', 'earnings.referral_tab'],
        ];
    }

    // ───────────────────────── switches ─────────────────────────

    #[DataProvider('tabs')]
    public function test_tab_on_renders(string $route, string $switch): void
    {
        $this->allOn();

        $this->actingAs($this->makeCustomer())->get(route($route))->assertOk();
    }

    #[DataProvider('tabs')]
    public function test_tab_switch_off_or_unset_is_404(string $route, string $switch): void
    {
        $this->allOn();
        $customer = $this->makeCustomer();

        Setting::set($switch, '0');
        $this->actingAs($customer)->get(route($route))->assertNotFound();

        Setting::clear($switch, 'global', null);
        $this->actingAs($customer)->get(route($route))->assertNotFound();
    }

    #[DataProvider('tabs')]
    public function test_master_switch_off_or_unset_404s_every_tab(string $route, string $switch): void
    {
        $this->allOn();
        $customer = $this->makeCustomer();

        Setting::set('earnings.enabled', '0');
        $this->actingAs($customer)->get(route($route))->assertNotFound();

        Setting::clear('earnings.enabled', 'global', null);
        $this->actingAs($customer)->get(route($route))->assertNotFound();
    }

    public function test_a_franchise_scoped_switch_applies_to_that_franchises_customers(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        Setting::set('earnings.enabled', '1', 'franchise', $franchise->id);
        Setting::set('earnings.wallet_tab', '1', 'franchise', $franchise->id);

        $inside = $this->makeCustomer();
        $inside->update(['franchise_id' => $franchise->id]);

        $this->actingAs($inside)->get(route('customer.earnings.wallet'))->assertOk();
        $this->actingAs($this->makeCustomer())->get(route('customer.earnings.wallet'))->assertNotFound();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->allOn();

        $this->get(route('customer.earnings.wallet'))->assertRedirect(route('customer.login'));
    }

    public function test_old_wallet_url_redirects_to_the_earnings_wallet(): void
    {
        $this->actingAs($this->makeCustomer())->get(route('customer.wallet'))->assertRedirect(route('customer.earnings.wallet'));
    }

    // ───────────────────────── navigation ─────────────────────────

    public function test_one_earnings_nav_entry_only_while_a_tab_is_on(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer)->get(route('customer.account'))->assertDontSee(route('customer.earnings.wallet'));

        $this->allOn();
        Setting::set('earnings.wallet_tab', '0');

        $page = $this->actingAs($customer)->get(route('customer.account'));
        $page->assertSee(route('customer.earnings.loyalty'))->assertDontSee(route('customer.earnings.wallet'));
        $this->assertSame(1, substr_count($page->getContent(), '>Earnings</p>'), 'exactly one Earnings entry on the account page');
    }

    public function test_only_enabled_tabs_are_listed_on_a_tab_page(): void
    {
        $this->allOn();
        Setting::set('earnings.referral_tab', '0');

        $this->actingAs($this->makeCustomer())->get(route('customer.earnings.wallet'))
            ->assertSee(route('customer.earnings.loyalty'))
            ->assertDontSee(route('customer.earnings.referrals'));
    }

    // ───────────────────────── wallet tab ─────────────────────────

    public function test_wallet_tab_shows_labels_and_booking_code(): void
    {
        $this->allOn();
        ['booking' => $booking, 'customer' => $customer] = $this->makeBookingScenario();
        app(WalletService::class)->credit($customer, 500, 'Refund for cancelled booking', "booking:{$booking->id}:wallet-refund");

        Livewire::actingAs($customer)->test(Wallet::class)
            ->assertSee('500.00')
            ->assertSee('Refund')
            ->assertSee($booking->code);
    }

    public function test_add_money_only_while_topup_is_on(): void
    {
        $this->allOn();
        $customer = $this->makeCustomer();

        Livewire::actingAs($customer)->test(Wallet::class)->assertDontSee('Add money');

        Setting::set('wallet.topup_enabled', '1');
        Livewire::actingAs($customer)->test(Wallet::class)->assertSee('Add money');
    }

    public function test_frozen_wallet_message_and_no_add_money(): void
    {
        $this->allOn();
        Setting::set('wallet.topup_enabled', '1');
        $customer = $this->makeCustomer();
        app(WalletFreezeService::class)->freeze($this->makeSuperAdmin(), $customer, 'review');

        Livewire::actingAs($customer)->test(Wallet::class)
            ->assertSee('Your wallet is on hold')
            ->assertDontSee('Add money');
    }

    public function test_top_up_off_is_refused_even_by_a_direct_call(): void
    {
        $this->allOn();

        Livewire::actingAs($this->makeCustomer())->test(Wallet::class)
            ->set('topUpAmount', '500')
            ->call('requestTopUp')
            ->assertSet('error', fn ($e) => $e !== '');
    }

    // ───────────────────────── loyalty tab ─────────────────────────

    public function test_loyalty_tab_shows_fifo_summary(): void
    {
        $this->allOn();
        $this->configureLegacyLoyalty();
        Setting::set('loyalty.points_expiry_days', '10');
        $customer = $this->makeCustomer();
        app(LoyaltyService::class)->earn($customer, 300, 'promo');
        app(LoyaltyService::class)->redeem($customer, 100);
        $this->travel(11)->days();
        app(LoyaltyService::class)->earn($customer, 40, 'promo');

        Livewire::actingAs($customer)->test(Loyalty::class)
            ->assertViewHas('summary', ['available' => 40, 'earned' => 340, 'redeemed' => 100, 'expired' => 200]);
    }

    public function test_redeem_preview_then_confirm_credits_the_server_amount(): void
    {
        $this->allOn();
        $this->configureLegacyLoyalty();
        $customer = $this->makeCustomer();
        app(LoyaltyService::class)->earn($customer, 500, 'promo');

        Livewire::actingAs($customer)->test(Loyalty::class)
            ->set('redeemPoints', '200')
            ->call('preview')
            ->assertSet('previewPoints', 200)
            ->assertSet('previewRupees', 20.0)
            ->assertSee('receive')
            ->call('confirmRedeem')
            ->assertSet('error', '');

        $this->assertEqualsWithDelta(20.0, (float) app(WalletService::class)->balance($customer), 0.001);
        $this->assertSame(300, app(LoyaltyService::class)->balance($customer));
    }

    public function test_a_manipulated_client_amount_cannot_change_what_is_credited(): void
    {
        $this->allOn();
        $this->configureLegacyLoyalty();
        $customer = $this->makeCustomer();
        app(LoyaltyService::class)->earn($customer, 500, 'promo');

        Livewire::actingAs($customer)->test(Loyalty::class)
            ->set('redeemPoints', '200')
            ->call('preview')
            ->set('previewRupees', 99999)         // tampered quote
            ->call('confirmRedeem');

        $this->assertEqualsWithDelta(20.0, (float) app(WalletService::class)->balance($customer), 0.001);

        // Tampering the points AFTER preview without re-reviewing is refused.
        Livewire::actingAs($customer)->test(Loyalty::class)
            ->set('redeemPoints', '100')
            ->call('preview')
            ->set('previewPoints', 300)
            ->call('confirmRedeem')
            ->assertSet('error', 'Please review the redemption again before confirming.');

        $this->assertSame(300, app(LoyaltyService::class)->balance($customer));
    }

    public function test_redeem_blocked_when_off_below_minimum_or_frozen(): void
    {
        $this->allOn();
        $this->configureLegacyLoyalty();
        $customer = $this->makeCustomer();
        app(LoyaltyService::class)->earn($customer, 500, 'promo');

        Livewire::actingAs($customer)->test(Loyalty::class)
            ->set('redeemPoints', '50')->call('preview')->call('confirmRedeem')
            ->assertSet('error', 'Minimum redemption is 100 points.');

        Setting::set('loyalty.redeem_enabled', '0');
        Livewire::actingAs($customer)->test(Loyalty::class)
            ->set('redeemPoints', '200')->call('preview')
            ->assertSet('error', 'Loyalty redemption is currently unavailable.');
        Setting::set('loyalty.redeem_enabled', '1');

        app(WalletFreezeService::class)->freeze($this->makeSuperAdmin(), $customer, 'review');
        Livewire::actingAs($customer)->test(Loyalty::class)
            ->set('redeemPoints', '200')->call('preview')->call('confirmRedeem')
            ->assertSet('error', fn ($e) => str_contains($e, 'frozen'));

        $this->assertSame(500, app(LoyaltyService::class)->balance($customer));
    }

    public function test_a_provider_gets_403_on_web_redeem(): void
    {
        $this->allOn();
        $this->configureLegacyLoyalty();
        ['provider' => $provider] = $this->makeBookingScenario();
        app(LoyaltyService::class)->earn($provider->user, 500, 'booking_completed');

        Livewire::actingAs($provider->user)->test(Loyalty::class)
            ->set('redeemPoints', '200')
            ->call('preview')
            ->assertForbidden();

        Livewire::actingAs($provider->user)->test(Loyalty::class)
            ->set('redeemPoints', '200')
            ->set('previewPoints', 200)
            ->call('confirmRedeem')
            ->assertForbidden();

        $this->assertSame(500, app(LoyaltyService::class)->balance($provider->user));
    }

    public function test_never_shows_a_combined_money_and_points_total(): void
    {
        $this->allOn();
        $this->configureLegacyLoyalty();
        $customer = $this->makeCustomer();
        app(WalletService::class)->credit($customer, 123.45, 'Refund', 'booking:1:wallet-refund');
        app(LoyaltyService::class)->earn($customer, 777, 'promo');

        Livewire::actingAs($customer)->test(Loyalty::class)->assertDontSee('123.45')->assertDontSee('900.45');
        Livewire::actingAs($customer)->test(Wallet::class)->assertDontSee('777')->assertDontSee('900.45');
    }

    // ───────────────────────── referrals tab ─────────────────────────

    public function test_referrals_tab_shows_own_code_and_own_referrals_only(): void
    {
        $this->allOn();
        $a = $this->makeCustomer();
        $a->update(['referral_code' => 'AAA111']);
        $b = $this->makeCustomer();
        $b->update(['referral_code' => 'BBB222']);
        $friendOfA = $this->makeCustomer();
        $friendOfA->update(['name' => 'Alicefriend Person']);
        $friendOfB = $this->makeCustomer();
        $friendOfB->update(['name' => 'Bobfriend Person']);
        Referral::create(['referrer_id' => $a->id, 'referred_id' => $friendOfA->id, 'status' => 'rewarded', 'reward_amount' => 50]);
        Referral::create(['referrer_id' => $b->id, 'referred_id' => $friendOfB->id, 'status' => 'pending']);

        Livewire::actingAs($a)->test(Referrals::class)
            ->assertSee('AAA111')->assertDontSee('BBB222')
            ->assertSee('Alicefriend')->assertDontSee('Bobfriend');
    }

    // ───────────────────────── isolation ─────────────────────────

    public function test_customer_a_never_sees_customer_b_on_any_tab(): void
    {
        $this->allOn();
        $this->configureLegacyLoyalty();
        $a = $this->makeCustomer();
        ['booking' => $bBooking, 'customer' => $b] = $this->makeBookingScenario();
        app(WalletService::class)->credit($b, 4321, 'B refund', "booking:{$bBooking->id}:wallet-refund");
        app(LoyaltyService::class)->earn($b, 8765, 'promo', $bBooking);

        Livewire::actingAs($a)->test(Wallet::class)->assertDontSee('4,321')->assertDontSee('B refund')->assertDontSee($bBooking->code);
        Livewire::actingAs($a)->test(Loyalty::class)->assertDontSee('8,765')->assertDontSee($bBooking->code);
    }

    public function test_a_foreign_booking_ref_in_my_own_ledger_never_reveals_its_code(): void
    {
        $this->allOn();
        $a = $this->makeCustomer();
        ['booking' => $foreign] = $this->makeBookingScenario();
        app(WalletService::class)->credit($a, 10, 'odd row', "booking:{$foreign->id}:wallet-refund");

        Livewire::actingAs($a)->test(Wallet::class)->assertDontSee($foreign->code);
    }
}
