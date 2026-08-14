<?php

namespace Tests\Feature\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Wallet;
use App\Services\Plans\SubscriptionService;
use App\Services\RazorpayService;
use App\Services\WalletTopUpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Payment Gateway Abstraction slice (continuation from 977c240). Real
 * gateway credentials never exist in this environment (.env.example has no
 * RAZORPAY_* entries -- confirmed by RazorpayService's own docblock, also
 * relied on by the pre-existing RazorpayService construction-safety fix) --
 * every test here sets fake, obviously-non-real config values and fakes the
 * outbound HTTP calls (Http::fake()), the same way any external-API-calling
 * code is safely tested without a live network dependency.
 */
class PaymentGatewayTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use RbacTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.razorpay.key_id' => 'rzp_test_fakekeyid123',
            'services.razorpay.key_secret' => 'fake-test-key-secret-never-real',
            'services.razorpay.webhook_secret' => 'fake-test-webhook-secret-never-real',
        ]);
    }

    private function fakeOrderResponse(string $orderId, int $amountPaise = 50000): void
    {
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => $orderId, 'amount' => $amountPaise, 'currency' => 'INR'], 200)]);
    }

    private function webhookSignatureFor(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload), config('services.razorpay.webhook_secret'));
    }

    private function capturedPayload(string $orderId, string $paymentId = 'pay_test_1'): array
    {
        return ['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['order_id' => $orderId, 'id' => $paymentId]]]];
    }

    private function failedPayload(string $orderId): array
    {
        return ['event' => 'payment.failed', 'payload' => ['payment' => ['entity' => ['order_id' => $orderId]]]];
    }

    private function postWebhook(array $payload)
    {
        return $this->postJson('/api/webhooks/razorpay', $payload, ['X-Razorpay-Signature' => $this->webhookSignatureFor($payload)]);
    }

    // ============================== 1. Contract satisfaction ==============================

    public function test_razorpay_service_satisfies_the_payment_gateway_contract(): void
    {
        $gateway = app(PaymentGateway::class);

        $this->assertInstanceOf(RazorpayService::class, $gateway);
        $this->assertSame('razorpay', $gateway->identifier());
        $this->assertSame('Razorpay', $gateway->displayName());
        $this->assertTrue($gateway->isConfigured());
        $this->assertNotNull($gateway->maskedPublicIdentifier());
        $this->assertStringStartsWith('rzp_test_', $gateway->maskedPublicIdentifier());
    }

    public function test_gateway_reports_not_configured_without_credentials(): void
    {
        config(['services.razorpay.key_id' => null, 'services.razorpay.key_secret' => null, 'services.razorpay.webhook_secret' => null]);

        $gateway = app(PaymentGateway::class);

        $this->assertFalse($gateway->isConfigured());
        $this->assertNull($gateway->maskedPublicIdentifier());
    }

    // ============================== 2. Booking payment ==============================

    public function test_booking_payment_create_order_then_webhook_capture_marks_booking_paid(): void
    {
        $this->fakeOrderResponse('order_booking_1');
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');
        $booking->update(['price_quoted' => 500]);

        $response = $this->actingAs($booking->customer, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/pay/create-order");

        $response->assertOk()->assertJsonPath('razorpay_order_id', 'order_booking_1');
        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id, 'purpose' => 'booking', 'gateway' => 'razorpay', 'status' => 'pending',
        ]);

        Notification::fake();
        $this->postWebhook($this->capturedPayload('order_booking_1'))->assertOk();

        $this->assertSame('paid', $booking->fresh()->payment_status);
        $this->assertDatabaseHas('payments', ['booking_id' => $booking->id, 'status' => 'captured']);
    }

    public function test_a_customer_cannot_create_a_payment_order_for_someone_elses_booking(): void
    {
        $this->fakeOrderResponse('order_should_not_be_created');
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');
        $otherCustomer = $this->makeCustomer();

        $this->actingAs($otherCustomer, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/pay/create-order")
            ->assertForbidden();

        $this->assertDatabaseMissing('payments', ['booking_id' => $booking->id]);
    }

    // ============================== 3. Wallet top-up ==============================

    public function test_wallet_topup_create_order_then_webhook_capture_credits_the_wallet(): void
    {
        $this->fakeOrderResponse('order_topup_1');
        $customer = $this->makeCustomer();

        $response = $this->actingAs($customer, 'sanctum')->postJson('/api/wallet/topup', ['amount' => 500]);

        $response->assertOk()->assertJsonPath('razorpay_order_id', 'order_topup_1');
        $this->assertDatabaseHas('payments', ['user_id' => $customer->id, 'purpose' => 'wallet_topup', 'status' => 'pending']);

        $this->postWebhook($this->capturedPayload('order_topup_1'))->assertOk();

        $this->assertSame(500.0, (float) Wallet::where('user_id', $customer->id)->value('balance'));
    }

    // ============================== 4. Subscription payment ==============================

    public function test_paid_subscription_purchase_then_webhook_capture_activates_it(): void
    {
        $this->fakeOrderResponse('order_sub_1');
        $customer = $this->makeCustomer();
        $plan = Plan::create([
            'name' => 'Paid Plan', 'slug' => 'paid-plan-'.Str::random(6), 'plan_family' => 'customer_membership',
            'scope_type' => 'global', 'eligible_actor_type' => 'customer', 'billing_cycle' => 'monthly',
            'price' => 199, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);

        $result = app(SubscriptionService::class)->initiateSubscribe($customer, 'customer', $plan);

        $this->assertTrue($result['requires_payment']);
        $subscription = Subscription::find($result['subscription_id']);
        $this->assertSame('pending_payment', $subscription->status);
        $this->assertDatabaseHas('payments', ['plan_subscription_id' => $subscription->id, 'purpose' => 'plan_subscription', 'status' => 'pending']);

        $this->postWebhook($this->capturedPayload('order_sub_1'))->assertOk();

        $this->assertSame('active', $subscription->fresh()->status);
    }

    public function test_subscription_payment_failure_leaves_it_unactivated(): void
    {
        $this->fakeOrderResponse('order_sub_fail_1');
        $customer = $this->makeCustomer();
        $plan = Plan::create([
            'name' => 'Paid Plan', 'slug' => 'paid-plan-'.Str::random(6), 'plan_family' => 'customer_membership',
            'scope_type' => 'global', 'eligible_actor_type' => 'customer', 'billing_cycle' => 'monthly',
            'price' => 199, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);

        $result = app(SubscriptionService::class)->initiateSubscribe($customer, 'customer', $plan);
        $subscription = Subscription::find($result['subscription_id']);

        $this->postWebhook($this->failedPayload('order_sub_fail_1'))->assertOk();

        $this->assertNotSame('active', $subscription->fresh()->status);
        $this->assertDatabaseHas('payments', ['plan_subscription_id' => $subscription->id, 'status' => 'failed']);
    }

    // ============================== 5. Webhook signature validation ==============================

    public function test_webhook_with_an_invalid_signature_is_rejected_and_does_not_capture(): void
    {
        $this->fakeOrderResponse('order_badsig_1');
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');
        $this->actingAs($booking->customer, 'sanctum')->postJson("/api/bookings/{$booking->id}/pay/create-order");

        $payload = $this->capturedPayload('order_badsig_1');
        $this->postJson('/api/webhooks/razorpay', $payload, ['X-Razorpay-Signature' => 'not-the-real-signature'])
            ->assertStatus(400);

        $this->assertDatabaseHas('payments', ['booking_id' => $booking->id, 'status' => 'pending']);
        $this->assertNotSame('paid', $booking->fresh()->payment_status);
    }

    public function test_webhook_with_no_signature_header_is_rejected(): void
    {
        $this->fakeOrderResponse('order_nosig_1');
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');
        $this->actingAs($booking->customer, 'sanctum')->postJson("/api/bookings/{$booking->id}/pay/create-order");

        $this->postJson('/api/webhooks/razorpay', $this->capturedPayload('order_nosig_1'))
            ->assertStatus(400);
    }

    // ============================== 6. Duplicate webhook idempotency ==============================

    public function test_duplicate_capture_webhook_does_not_double_credit_a_wallet_topup(): void
    {
        $this->fakeOrderResponse('order_topup_dup_1');
        $customer = $this->makeCustomer();
        $this->actingAs($customer, 'sanctum')->postJson('/api/wallet/topup', ['amount' => 500]);

        $payload = $this->capturedPayload('order_topup_dup_1');
        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk(); // exact same event, delivered twice

        $this->assertSame(500.0, (float) Wallet::where('user_id', $customer->id)->value('balance'), 'A retried/duplicate webhook must not double-credit.');
        $this->assertSame(1, \App\Models\WalletTransaction::whereHas('wallet', fn ($q) => $q->where('user_id', $customer->id))->count());
    }

    public function test_duplicate_capture_webhook_sends_only_one_notification(): void
    {
        $this->fakeOrderResponse('order_notif_dup_1');
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');
        $this->actingAs($booking->customer, 'sanctum')->postJson("/api/bookings/{$booking->id}/pay/create-order");

        Notification::fake();
        $payload = $this->capturedPayload('order_notif_dup_1');
        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        Notification::assertSentToTimes($booking->customer, \App\Notifications\PaymentStatusNotification::class, 1);
    }

    // ============================== 7. Refund authorization ==============================

    public function test_cancelling_a_paid_booking_refunds_via_the_gateway_when_authorized(): void
    {
        Http::fake([
            'api.razorpay.com/v1/orders' => Http::response(['id' => 'order_refund_1', 'amount' => 50000, 'currency' => 'INR'], 200),
            'api.razorpay.com/v1/payments/*/refund' => Http::response(['id' => 're_test_1', 'amount' => 50000], 200),
        ]);
        ['booking' => $booking, 'zone' => $zone] = $this->makeBookingScenario('searching_provider');
        $booking->update(['price_quoted' => 500]);
        $this->actingAs($booking->customer, 'sanctum')->postJson("/api/bookings/{$booking->id}/pay/create-order");
        $this->postWebhook($this->capturedPayload('order_refund_1'));
        $this->assertSame('paid', $booking->fresh()->payment_status);

        // Bookings\Show::mount() requires bookings.view to open the screen
        // at all (see 977c240) -- granted alongside bookings.cancel here,
        // matching how every real seeded role composes both together.
        $actor = $this->makeUserWithPermission('bookings.cancel', 'zone', $zone->id);
        $this->grantPermission($actor, 'bookings.view', 'zone', $zone->id);
        \Livewire\Livewire::actingAs($actor)->test(\App\Livewire\Bookings\Show::class, ['bookingId' => $booking->id])
            ->set('cancelReason', 'test cancel')
            ->call('cancel')
            ->assertSet('flashType', 'success');

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/refund'));
        $this->assertDatabaseHas('payments', ['booking_id' => $booking->id, 'status' => 'refunded']);
    }

    public function test_cancelling_a_paid_booking_without_permission_does_not_refund(): void
    {
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_norefund_1', 'amount' => 50000, 'currency' => 'INR'], 200)]);
        ['booking' => $booking, 'zone' => $zone] = $this->makeBookingScenario('searching_provider');
        $booking->update(['price_quoted' => 500]);
        $this->actingAs($booking->customer, 'sanctum')->postJson("/api/bookings/{$booking->id}/pay/create-order");
        $this->postWebhook($this->capturedPayload('order_norefund_1'));

        // Holds bookings.view (so the screen itself opens -- see the comment
        // in the "authorized" test above) but deliberately NOT bookings.cancel.
        $actor = $this->makeUserWithNoPermissions();
        $this->grantPermission($actor, 'bookings.view', 'zone', $zone->id);
        \Livewire\Livewire::actingAs($actor)->test(\App\Livewire\Bookings\Show::class, ['bookingId' => $booking->id])
            ->set('cancelReason', 'test cancel')
            ->call('cancel')
            ->assertSet('flashType', 'error');

        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), '/refund'));
        $this->assertNotSame('cancelled', $booking->fresh()->status);
        $this->assertDatabaseMissing('payments', ['booking_id' => $booking->id, 'status' => 'refunded']);
    }

    // ============================== 8/9. Provider configuration + disabled gateway ==============================

    public function test_disabled_online_payments_blocks_booking_order_creation(): void
    {
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');
        Setting::set('payment.online_enabled', '0');

        $this->actingAs($booking->customer, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/pay/create-order")
            ->assertStatus(422);

        $this->assertDatabaseMissing('payments', ['booking_id' => $booking->id]);
        Http::assertNothingSent();
    }

    public function test_disabled_online_payments_blocks_wallet_topup(): void
    {
        $customer = $this->makeCustomer();
        Setting::set('payment.online_enabled', '0');

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/wallet/topup', ['amount' => 500])
            ->assertStatus(422);

        $this->assertDatabaseMissing('payments', ['user_id' => $customer->id]);
    }

    public function test_disabled_online_payments_blocks_subscription_purchase(): void
    {
        Setting::set('payment.online_enabled', '0');
        $customer = $this->makeCustomer();
        $plan = Plan::create([
            'name' => 'Paid Plan', 'slug' => 'paid-plan-'.Str::random(6), 'plan_family' => 'customer_membership',
            'scope_type' => 'global', 'eligible_actor_type' => 'customer', 'billing_cycle' => 'monthly',
            'price' => 199, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('disabled');
        app(SubscriptionService::class)->initiateSubscribe($customer, 'customer', $plan);
    }

    public function test_settings_payment_tab_shows_gateway_status_without_exposing_secrets(): void
    {
        $admin = $this->makeSuperAdmin();

        $component = \Livewire\Livewire::actingAs($admin)->test(\App\Livewire\Settings\Manage::class)
            ->set('activeTab', 'payment');

        $component->assertSee('Razorpay')->assertSee('Configured');
        $component->assertDontSee(config('services.razorpay.key_secret'));
        $component->assertDontSee(config('services.razorpay.webhook_secret'));
    }

    // ============================== 10. Secrets never exposed ==============================

    public function test_create_order_response_never_contains_the_key_secret_or_webhook_secret(): void
    {
        $this->fakeOrderResponse('order_secretcheck_1');
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');

        $response = $this->actingAs($booking->customer, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/pay/create-order");

        $raw = $response->getContent();
        $this->assertStringNotContainsString(config('services.razorpay.key_secret'), $raw);
        $this->assertStringNotContainsString(config('services.razorpay.webhook_secret'), $raw);
    }

    public function test_masked_public_identifier_never_reveals_the_full_key(): void
    {
        $gateway = app(PaymentGateway::class);
        $masked = $gateway->maskedPublicIdentifier();

        $this->assertNotSame(config('services.razorpay.key_id'), $masked);
        $this->assertStringNotContainsString(config('services.razorpay.key_secret'), $masked);
    }
}
