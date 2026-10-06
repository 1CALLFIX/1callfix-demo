<?php

namespace Tests\Feature\Payments;

use App\Actions\CustomerCancelBookingAction;
use App\Actions\PlaceBookingOnHoldAction;
use App\Exceptions\PaymentGatewayException;
use App\Livewire\Customer\Earnings\Wallet;
use App\Models\Plan;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Pre-merge: the gateway-failure rule (customers see one generic sentence, never gateway text) holds on the
 * remaining customer payment paths — web wallet top-up, membership purchase API, and the cancellation-charge
 * payment. Run with APP_DEBUG on, so a leak would show the exception class, file and trace too.
 */
class GatewayFailureOtherPaymentPathsTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private const SAFE = 'We could not start the payment right now. Please try again in a moment.';

    private const LEAKS = [
        'Razorpay', 'razorpay', 'receipt', 'BAD_REQUEST', 'Authentication failed', 'topup-', 'cancel-',
        'RuntimeException', 'PaymentGatewayException', 'App\\Exceptions', 'Exception', 'trace', '.php', 'error":',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.debug' => true,
            'services.razorpay.key_id' => 'rzp_test_failkey123',
            'services.razorpay.key_secret' => 'fake-fail-key-secret-never-real',
            'services.razorpay.webhook_secret' => 'fake-fail-webhook-secret-never-real',
        ]);
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(
            ['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'Authentication failed']], 401,
        )]);
    }

    private function assertNoLeak(string $text): void
    {
        foreach (self::LEAKS as $needle) {
            $this->assertStringNotContainsString($needle, $text, "Leaked [{$needle}]");
        }
    }

    public function test_a_failed_wallet_top_up_on_the_web_shows_only_the_generic_sentence(): void
    {
        foreach ([
            'earnings.enabled' => '1', 'earnings.wallet_tab' => '1', 'wallet.topup_enabled' => '1',
            'wallet.customer_min_topup' => '1', 'wallet.customer_max_topup' => '10000', 'wallet.customer_max_balance' => '100000',
            'wallet.customer_daily_topup_limit' => '100000', 'wallet.customer_monthly_topup_limit' => '100000',
        ] as $key => $value) {
            Setting::set($key, $value);
        }
        $customer = $this->makeCustomer();

        $component = Livewire::actingAs($customer)->test(Wallet::class)->set('topUpAmount', '100')->call('requestTopUp');

        $this->assertSame(self::SAFE, $component->get('error'));
        $this->assertNoLeak($component->html());
    }

    public function test_a_failed_membership_purchase_returns_only_the_generic_sentence(): void
    {
        $customer = $this->makeCustomer();
        $plan = Plan::create([
            'name' => 'Paid', 'slug' => 'paid-plan', 'plan_family' => 'customer_membership', 'scope_type' => 'global',
            'eligible_actor_type' => 'customer', 'billing_cycle' => 'monthly', 'price' => 99,
            'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);

        $res = $this->actingAs($customer, 'sanctum')->postJson("/api/plans/{$plan->id}/subscribe", ['acting_as' => 'customer']);

        $this->assertSame(422, $res->status());
        $this->assertSame(self::SAFE, $res->json('message'));
        $this->assertSame(['message'], array_keys($res->json()));
        $this->assertNoLeak($res->getContent());
    }

    public function test_a_failed_cancellation_charge_payment_returns_only_the_generic_sentence(): void
    {
        // The same unlocked mid-work scenario CustomerCancellationPolicyTest uses: a ₹50 charge, no wallet.
        Setting::set('cancellation.interim_cap_percent', '50');
        Setting::set('cancellation.visit_fee_type', 'flat');
        Setting::set('cancellation.visit_fee_value', '50');
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'in_progress', 'start_otp_verified_at' => now()]);
        Carbon::setTestNow(now()->subDays(11));
        app(PlaceBookingOnHoldAction::class)->execute($s['booking']->id, 'awaiting_spares', 'Waiting for the part', [
            'work_amount' => 100, 'sourced_by' => 'provider', 'expected_at' => now()->addDays(2)->toDateString(),
        ]);
        Carbon::setTestNow();
        $booking = $s['booking']->fresh();
        $token = app(CustomerCancelBookingAction::class)->quote($booking)['token'];

        $res = $this->actingAs($booking->customer, 'sanctum')->postJson("/api/bookings/{$booking->id}/cancel", [
            'reason' => 'Changed my mind', 'quote_token' => $token,
        ]);

        $this->assertSame(409, $res->status());
        $this->assertSame(self::SAFE, $res->json('message'));
        $this->assertNoLeak($res->getContent());
        $this->assertSame('on_hold', $booking->fresh()->status, 'The booking was not cancelled by a failed payment.');
    }

    public function test_the_cancellation_action_throws_the_safe_exception_with_the_detail_kept_internal(): void
    {
        Setting::set('cancellation.interim_cap_percent', '50');
        Setting::set('cancellation.visit_fee_type', 'flat');
        Setting::set('cancellation.visit_fee_value', '50');
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'in_progress', 'start_otp_verified_at' => now()]);
        Carbon::setTestNow(now()->subDays(11));
        app(PlaceBookingOnHoldAction::class)->execute($s['booking']->id, 'awaiting_spares', 'Waiting for the part', [
            'work_amount' => 100, 'sourced_by' => 'provider', 'expected_at' => now()->addDays(2)->toDateString(),
        ]);
        Carbon::setTestNow();
        $booking = $s['booking']->fresh();
        $action = app(CustomerCancelBookingAction::class);

        try {
            $action->execute($booking->id, $booking->customer_id, 'Changed my mind', $action->quote($booking)['token']);
            $this->fail('Expected a gateway exception.');
        } catch (PaymentGatewayException $e) {
            $this->assertSame(self::SAFE, $e->getMessage());
            $this->assertStringContainsString('cancel-', $e->detail);
        }
    }
}
