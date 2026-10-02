<?php

namespace Tests\Feature\Payments;

use App\Models\Payment;
use App\Models\PaymentWebhookLog;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payments\RazorpayWebhookHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * RazorpayWebhookHandler::handleCaptured() must compare the gateway's
 * captured amount (paise) with the Payment row before marking anything paid.
 * On a mismatch: Payment stays pending, nothing downstream runs, the full
 * details are logged, admins are alerted, and the endpoint still answers 200
 * so Razorpay does not retry the same wrong event forever.
 */
class RazorpayCapturedAmountCheckTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private const PURPOSES = [
        'booking', 'wallet_topup', 'plan_subscription', 'parcel_order', 'taxi_ride',
        'property_reservation', 'marketplace_order', 'rental_reservation', 'hotel_reservation',
        'booking_bundle', 'cancellation_fee',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.razorpay.key_id' => 'rzp_test_fakekeyid123',
            'services.razorpay.key_secret' => 'fake-test-key-secret-never-real',
            'services.razorpay.webhook_secret' => 'fake-test-webhook-secret-never-real',
        ]);
    }

    private function makePayment(string $purpose, float $amount = 499.50, array $extra = []): Payment
    {
        return Payment::create(array_merge([
            'purpose' => $purpose,
            'amount' => $amount,
            'gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.Str::random(10),
            'status' => 'pending',
        ], $extra));
    }

    private function payload(Payment $payment, mixed $amount, ?string $paymentId = 'pay_amt_1'): array
    {
        $entity = ['order_id' => $payment->gateway_order_id, 'id' => $paymentId, 'currency' => 'INR'];
        if ($amount !== null) {
            $entity['amount'] = $amount;
        }

        return ['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => $entity]]];
    }

    private function postWebhook(array $payload)
    {
        return $this->postJson('/api/webhooks/razorpay', $payload, [
            'X-Razorpay-Signature' => hash_hmac('sha256', json_encode($payload), config('services.razorpay.webhook_secret')),
        ]);
    }

    private function topUpPayment(float $amount = 250.00): array
    {
        $user = $this->makeCustomer();

        return [$user, $this->makePayment('wallet_topup', $amount, ['user_id' => $user->id])];
    }

    // ============================== exact match ==============================

    public function test_exact_amount_in_paise_is_captured(): void
    {
        [$user, $payment] = $this->topUpPayment(250.00);

        $this->postWebhook($this->payload($payment, 25000))->assertOk();

        $this->assertSame('captured', $payment->fresh()->status);
        $this->assertSame(250.0, (float) Wallet::where('user_id', $user->id)->value('balance'));
        $this->assertDatabaseHas('payment_webhook_logs', ['gateway_order_id' => $payment->gateway_order_id, 'outcome' => 'captured', 'processed' => 1]);
    }

    public function test_fractional_rupee_amounts_compare_exactly_in_paise(): void
    {
        $payment = $this->makePayment('parcel_order', 499.50);

        $result = app(RazorpayWebhookHandler::class)->handleCaptured($this->payload($payment, 49950));

        $this->assertSame('captured', $result['outcome']);
        $this->assertSame('captured', $payment->fresh()->status);
    }

    // ============================== mismatch ==============================

    public function test_a_lower_captured_amount_is_rejected_and_nothing_is_credited(): void
    {
        [$user, $payment] = $this->topUpPayment(250.00);

        $this->postWebhook($this->payload($payment, 24999))->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->captured_at);
        $this->assertNull($payment->fresh()->gateway_payment_id);
        $this->assertSame(0, Wallet::where('user_id', $user->id)->count(), 'no wallet may be created or credited');
        $this->assertDatabaseMissing('wallet_transactions', ['ref' => "topup:{$payment->id}"]);
        $this->assertDatabaseHas('payment_webhook_logs', [
            'gateway_order_id' => $payment->gateway_order_id, 'outcome' => 'amount_mismatch', 'processed' => 0, 'signature_valid' => 1,
        ]);
    }

    public function test_a_higher_captured_amount_is_rejected_and_nothing_is_credited(): void
    {
        [$user, $payment] = $this->topUpPayment(250.00);

        $this->postWebhook($this->payload($payment, 25001))->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(0, Wallet::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('payment_webhook_logs', ['gateway_order_id' => $payment->gateway_order_id, 'outcome' => 'amount_mismatch', 'processed' => 0]);
    }

    public function test_a_missing_or_non_numeric_amount_is_a_mismatch_never_trusted(): void
    {
        foreach ([null, 'abc', 250.5, '250.00'] as $i => $bad) {
            $payment = $this->makePayment('parcel_order', 250.00);

            $result = app(RazorpayWebhookHandler::class)->handleCaptured($this->payload($payment, $bad));

            $this->assertSame('amount_mismatch', $result['outcome'], "case #{$i}");
            $this->assertSame('pending', $payment->fresh()->status, "case #{$i}");
        }
    }

    public function test_every_purpose_is_checked(): void
    {
        foreach (self::PURPOSES as $purpose) {
            foreach ([4999, 5001] as $wrong) {
                $payment = $this->makePayment($purpose, 50.00);

                $result = app(RazorpayWebhookHandler::class)->handleCaptured($this->payload($payment, $wrong));

                $this->assertSame('amount_mismatch', $result['outcome'], "{$purpose} @ {$wrong}");
                $this->assertSame('pending', $payment->fresh()->status, "{$purpose} @ {$wrong}");
            }

            // wallet_topup's exact match is covered with a real user above; every other purpose here has no related row to act on.
            if ($purpose !== 'wallet_topup') {
                $ok = $this->makePayment($purpose, 50.00);
                $result = app(RazorpayWebhookHandler::class)->handleCaptured($this->payload($ok, 5000));

                $this->assertSame('captured', $result['outcome'], "{$purpose} exact match");
                $this->assertSame('captured', $ok->fresh()->status, "{$purpose} exact match");
            }
        }
    }

    public function test_a_booking_payment_with_the_wrong_amount_does_not_mark_the_booking_paid_or_release_dispatch(): void
    {
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');
        $booking->update(['price_quoted' => 500, 'payment_status' => 'pending']);
        $payment = $this->makePayment('booking', 500.00, ['booking_id' => $booking->id]);

        $this->postWebhook($this->payload($payment, 100))->assertOk();

        $this->assertSame('pending', $booking->fresh()->payment_status);
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_a_mismatch_logs_full_details_and_raises_the_ops_alert(): void
    {
        $alerts = \Mockery::mock(\App\Services\AdminOpsAlertService::class);
        $alerts->shouldReceive('paymentAmountMismatch')->once()->with(\Mockery::on(fn ($p) => $p instanceof Payment));
        $alerts->shouldNotReceive('paymentCaptured');
        $this->app->instance(\App\Services\AdminOpsAlertService::class, $alerts);

        Log::spy();
        $payment = $this->makePayment('parcel_order', 250.00);

        app(RazorpayWebhookHandler::class)->handleCaptured($this->payload($payment, 99900, 'pay_wrong_1'));

        Log::shouldHaveReceived('error')->once()->withArgs(function ($message, $context) use ($payment) {
            return str_contains($message, 'does not match')
                && $context['payment_id'] === $payment->id
                && $context['purpose'] === 'parcel_order'
                && $context['gateway_order_id'] === $payment->gateway_order_id
                && $context['gateway_payment_id'] === 'pay_wrong_1'
                && $context['expected_paise'] === 25000
                && $context['captured_paise'] === 99900
                && $context['currency'] === 'INR';
        });
    }

    public function test_the_alert_has_its_own_event_key_and_copy(): void
    {
        $payment = $this->makePayment('parcel_order', 250.00);
        $notification = new \App\Notifications\AdminOpsAlertNotification('payment_amount_mismatch', $payment, ['push']);

        $this->assertSame('admin.ops_payment_amount_mismatch', $notification->eventKey());
        $copy = $notification->toPush(new User());
        $this->assertSame('Payment amount mismatch', $copy['title']);
        $this->assertStringContainsString("payment #{$payment->id}", $copy['body']);
        $this->assertStringContainsString('NOT marked paid', $copy['body']);
    }

    // ============================== idempotency ==============================

    public function test_duplicate_webhook_after_a_good_capture_is_still_idempotent(): void
    {
        [$user, $payment] = $this->topUpPayment(250.00);
        $payload = $this->payload($payment, 25000);

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $this->assertSame(250.0, (float) Wallet::where('user_id', $user->id)->value('balance'), 'credited exactly once');
        $this->assertSame(1, \App\Models\WalletTransaction::where('ref', "topup:{$payment->id}")->count());
        $this->assertSame(1, PaymentWebhookLog::where('outcome', 'captured')->count());
        $this->assertSame(1, PaymentWebhookLog::where('outcome', 'already_processed')->count());
    }

    public function test_an_already_captured_payment_is_not_re_checked_or_downgraded_by_a_later_wrong_amount_event(): void
    {
        [$user, $payment] = $this->topUpPayment(250.00);
        $this->postWebhook($this->payload($payment, 25000))->assertOk();

        $result = app(RazorpayWebhookHandler::class)->handleCaptured($this->payload($payment, 1));

        $this->assertSame('already_processed', $result['outcome']);
        $this->assertSame('captured', $payment->fresh()->status);
        $this->assertSame(250.0, (float) Wallet::where('user_id', $user->id)->value('balance'));
    }

    public function test_a_corrected_event_after_a_mismatch_can_still_capture(): void
    {
        [$user, $payment] = $this->topUpPayment(250.00);

        $this->postWebhook($this->payload($payment, 100))->assertOk();
        $this->assertSame('pending', $payment->fresh()->status);

        $this->postWebhook($this->payload($payment, 25000))->assertOk();
        $this->assertSame('captured', $payment->fresh()->status);
        $this->assertSame(250.0, (float) Wallet::where('user_id', $user->id)->value('balance'));
    }
}
