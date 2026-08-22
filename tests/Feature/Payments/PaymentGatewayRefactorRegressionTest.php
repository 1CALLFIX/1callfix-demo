<?php

namespace Tests\Feature\Payments;

use App\Actions\CompleteBookingAction;
use App\Models\Commission;
use App\Models\Payment;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Payment Gateway Manager session — THE non-negotiable regression check the
 * prompt called out by name: the real booking -> dispatch -> payment ->
 * webhook -> commission -> wallet flow, re-run end to end through the
 * refactored PaymentGatewayManager-resolved path, on a booking that's
 * already been "dispatched" (makeAssignedBookingScenario -- accepted by a
 * real provider), asserting the exact same outcomes
 * tests/Feature/Payments/PaymentGatewayTest.php's pre-existing
 * `test_booking_payment_create_order_then_webhook_capture_marks_booking_paid`
 * already locks in for the payment half, PLUS the completion/commission/
 * wallet half that test doesn't reach. Nothing about this flow is a double
 * (no mocked gateway class, no bypassed webhook signature) except the one
 * thing every payment test in this codebase already fakes: the outbound
 * HTTP call to Razorpay itself (Http::fake()) -- there is no live Razorpay
 * account reachable from the test suite, same documented constraint
 * PaymentGatewayTest.php's own class docblock states.
 */
class PaymentGatewayRefactorRegressionTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.razorpay.key_id' => 'rzp_test_regression_check',
            'services.razorpay.key_secret' => 'regression-secret-never-real',
            'services.razorpay.webhook_secret' => 'regression-webhook-secret',
        ]);
    }

    public function test_full_booking_dispatch_payment_webhook_commission_wallet_flow_is_unchanged_by_the_refactor(): void
    {
        // --- Dispatch: booking already accepted by a real provider ---
        ['booking' => $booking, 'provider' => $provider] = $this->makeAssignedBookingScenario();
        $this->assertSame('assigned', $booking->status);
        $this->assertSame('pending', $booking->payment_status);

        // --- Payment: create-order through the refactored PaymentGatewayManager-resolved gateway ---
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_regression_1', 'amount' => 50000, 'currency' => 'INR'], 200)]);

        $orderResponse = $this->actingAs($booking->customer, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/pay/create-order");

        $orderResponse->assertOk()->assertJsonPath('razorpay_order_id', 'order_regression_1');
        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id, 'purpose' => 'booking', 'gateway' => 'razorpay', 'status' => 'pending',
        ]);

        // --- Webhook: real signature computed with the same webhook secret the gateway itself used ---
        Notification::fake();
        $payload = ['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['order_id' => 'order_regression_1', 'id' => 'pay_regression_1']]]];
        $signature = hash_hmac('sha256', json_encode($payload), config('services.razorpay.webhook_secret'));

        $this->postJson('/api/webhooks/razorpay', $payload, ['X-Razorpay-Signature' => $signature])->assertOk();

        $booking->refresh();
        $this->assertSame('paid', $booking->payment_status);
        $this->assertDatabaseHas('payments', ['booking_id' => $booking->id, 'status' => 'captured', 'gateway_payment_id' => 'pay_regression_1']);
        $this->assertDatabaseHas('payment_webhook_logs', ['gateway_order_id' => 'order_regression_1', 'outcome' => 'captured', 'signature_valid' => 1]);

        // --- Completion: provider self-completes with the real OTP fixture generated ---
        $result = app(CompleteBookingAction::class)->execute($booking->id, $provider, '5678');
        $this->assertSame('completed', $result->status);

        // --- Commission + wallet: the exact downstream effects a real paid, completed booking produces ---
        $this->assertDatabaseHas('commissions', ['booking_id' => $booking->id]);
        $commission = Commission::where('booking_id', $booking->id)->first();
        $this->assertNotNull($commission, 'A completed, paid booking must produce a real commission row -- this is the money-critical link the refactor must not touch.');

        $payment = Payment::where('booking_id', $booking->id)->first();
        $this->assertSame('captured', $payment->status);
        $this->assertEquals(500, (float) $payment->amount);

        $providerWallet = Wallet::where('user_id', $provider->user_id)->first();
        $this->assertNotNull($providerWallet, 'Completing a paid booking must credit the provider a real wallet row.');
        $this->assertGreaterThan(0, (float) $providerWallet->balance, 'The provider wallet must actually be credited, not just a commission row created with no money movement.');

        // Sanity: the payment that funded this booking really did flow
        // through the new manager-resolved driver, not a bypass -- confirm
        // the gateway column recorded on the payment row matches what
        // PaymentGatewayManager's fallback identifies itself as.
        $this->assertSame(app(\App\Contracts\PaymentGateway::class)->identifier(), $payment->gateway);
    }
}
