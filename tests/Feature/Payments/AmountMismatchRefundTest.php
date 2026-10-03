<?php

namespace Tests\Feature\Payments;

use App\Contracts\PaymentGateway;
use App\Livewire\Operations\Health;
use App\Models\ActivityLog;
use App\Models\Payment;
use App\Models\PaymentWebhookLog;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\PaymentUnderReviewNotification;
use App\Services\Payments\AmountMismatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Step 0c — refund path for a Razorpay capture whose amount did not match:
 * Super Admin only, reason required, audit-logged, exactly the captured
 * amount, idempotent, never automatic; customer is told it is under review
 * with admin-editable copy.
 */
class AmountMismatchRefundTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.razorpay.key_id' => 'rzp_test_fakekeyid123',
            'services.razorpay.key_secret' => 'fake-test-key-secret-never-real',
            'services.razorpay.webhook_secret' => 'fake-test-webhook-secret-never-real',
        ]);
        Cache::flush();
    }

    /** @return array{0: User, 1: Payment, 2: PaymentWebhookLog} a wallet top-up of ₹250 for which Razorpay captured ₹249.99 */
    private function mismatch(int $capturedPaise = 24999, string $gatewayPaymentId = 'pay_mm_1'): array
    {
        $user = $this->makeCustomer();
        $payment = Payment::create([
            'purpose' => 'wallet_topup', 'amount' => 250.00, 'gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.Str::random(10), 'status' => 'pending', 'user_id' => $user->id,
        ]);

        $payload = ['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
            'order_id' => $payment->gateway_order_id, 'id' => $gatewayPaymentId, 'amount' => $capturedPaise, 'currency' => 'INR',
        ]]]];

        $this->postJson('/api/webhooks/razorpay', $payload, [
            'X-Razorpay-Signature' => hash_hmac('sha256', json_encode($payload), config('services.razorpay.webhook_secret')),
        ])->assertOk();

        $log = PaymentWebhookLog::where('gateway_order_id', $payment->gateway_order_id)->latest('id')->firstOrFail();
        $this->assertSame('amount_mismatch', $log->outcome);

        return [$user, $payment, $log];
    }

    private function gatewayExpecting(float $amount, string $paymentId = 'pay_mm_1', int $times = 1): void
    {
        $mock = Mockery::mock(PaymentGateway::class);
        $mock->shouldReceive('refund')->times($times)->withArgs(fn ($id, $amt) => $id === $paymentId && abs($amt - $amount) < 0.001)->andReturn(['id' => 'rfnd_1']);
        $this->app->instance(PaymentGateway::class, $mock);
    }

    private function gatewayNeverCalled(): void
    {
        $mock = Mockery::mock(PaymentGateway::class);
        $mock->shouldNotReceive('refund');
        $this->app->instance(PaymentGateway::class, $mock);
    }

    // ============================== refund ==============================

    public function test_super_admin_refunds_exactly_the_captured_amount_with_a_reason_and_it_is_audit_logged(): void
    {
        [, $payment, $log] = $this->mismatch(24999);
        $admin = $this->makeSuperAdmin();
        $this->gatewayExpecting(249.99);

        Livewire::actingAs($admin)->test(Health::class)
            ->call('startRefund', $log->id)
            ->set('refundReason', 'Customer paid ₹249.99 by mistake')
            ->call('refundMismatch')
            ->assertSet('flashType', 'success');

        $this->assertSame('amount_mismatch_refunded', $log->fresh()->outcome);
        $this->assertTrue($log->fresh()->processed);
        $this->assertSame('pending', $payment->fresh()->status, 'the Payment row is never marked paid by a refund');

        $audit = ActivityLog::where('description', 'like', 'Refunded mismatched payment%')->first();
        $this->assertNotNull($audit);
        $this->assertSame($admin->id, $audit->causer_id);
        $this->assertSame(249.99, (float) $audit->properties['amount']);
        $this->assertSame('Customer paid ₹249.99 by mistake', $audit->properties['reason']);
    }

    public function test_refund_is_idempotent_a_second_click_does_not_reach_the_gateway(): void
    {
        [, , $log] = $this->mismatch();
        $admin = $this->makeSuperAdmin();
        $this->gatewayExpecting(249.99, times: 1);

        $component = Livewire::actingAs($admin)->test(Health::class);
        $component->call('startRefund', $log->id)->set('refundReason', 'first')->call('refundMismatch')->assertSet('flashType', 'success');
        $component->call('startRefund', $log->id)->set('refundReason', 'second')->call('refundMismatch')->assertSet('flashType', 'error');
    }

    public function test_a_redelivered_event_for_an_already_refunded_gateway_payment_cannot_be_refunded_again(): void
    {
        [, $payment, $log] = $this->mismatch(24999, 'pay_dup');
        $admin = $this->makeSuperAdmin();
        $this->gatewayExpecting(249.99, 'pay_dup', 1);

        app(AmountMismatchService::class)->refundCaptured($log, $admin, 'first');

        // Same gateway payment shows up again as a fresh mismatch log row.
        $dup = PaymentWebhookLog::create([
            'gateway' => 'razorpay', 'event' => 'payment.captured', 'gateway_order_id' => $payment->gateway_order_id,
            'gateway_payment_id' => 'pay_dup', 'payment_id' => $payment->id, 'signature_valid' => true, 'processed' => false,
            'outcome' => 'amount_mismatch', 'payload' => $log->payload, 'created_at' => now(),
        ]);

        $result = app(AmountMismatchService::class)->refundCaptured($dup, $admin, 'again');

        $this->assertFalse($result['ok']);
        $this->assertSame('amount_mismatch', $dup->fresh()->outcome);
    }

    public function test_reason_is_required(): void
    {
        [, , $log] = $this->mismatch();
        $this->gatewayNeverCalled();

        Livewire::actingAs($this->makeSuperAdmin())->test(Health::class)
            ->call('startRefund', $log->id)
            ->set('refundReason', '')
            ->call('refundMismatch')
            ->assertHasErrors('refundReason');

        $this->assertSame('amount_mismatch', $log->fresh()->outcome);

        $result = app(AmountMismatchService::class)->refundCaptured($log, $this->makeSuperAdmin(), '   ');
        $this->assertFalse($result['ok']);
    }

    public function test_only_a_super_admin_can_refund_even_with_operations_manage(): void
    {
        [, , $log] = $this->mismatch();
        $this->gatewayNeverCalled();

        $manager = $this->makeUserWithPermission('operations.manage', 'global');
        $this->grantPermission($manager, 'operations.view', 'global');

        Livewire::actingAs($manager)->test(Health::class)
            ->call('startRefund', $log->id)
            ->assertForbidden();

        Livewire::actingAs($manager)->test(Health::class)
            ->set('refundingLogId', $log->id)->set('refundReason', 'x y z')
            ->call('refundMismatch')
            ->assertForbidden();

        $result = app(AmountMismatchService::class)->refundCaptured($log, $manager, 'reason');
        $this->assertFalse($result['ok']);
        $this->assertSame('amount_mismatch', $log->fresh()->outcome);
    }

    public function test_only_amount_mismatch_rows_are_refundable(): void
    {
        [, , $log] = $this->mismatch();
        $this->gatewayNeverCalled();
        $log->update(['outcome' => 'captured']);

        $result = app(AmountMismatchService::class)->refundCaptured($log, $this->makeSuperAdmin(), 'reason');

        $this->assertFalse($result['ok']);
    }

    public function test_a_gateway_failure_changes_nothing_is_logged_and_can_be_retried(): void
    {
        [, , $log] = $this->mismatch();
        $admin = $this->makeSuperAdmin();

        $failing = Mockery::mock(PaymentGateway::class);
        $failing->shouldReceive('refund')->once()->andThrow(new \RuntimeException('gateway down'));
        $this->app->instance(PaymentGateway::class, $failing);

        $result = app(AmountMismatchService::class)->refundCaptured($log, $admin, 'try');

        $this->assertFalse($result['ok']);
        $this->assertSame('amount_mismatch', $log->fresh()->outcome);
        $this->assertNotNull(ActivityLog::where('description', 'like', 'Mismatch refund FAILED%')->first());

        $this->app->forgetInstance(AmountMismatchService::class);
        $this->gatewayExpecting(249.99);
        $this->assertTrue(app(AmountMismatchService::class)->refundCaptured($log->fresh(), $admin, 'retry')['ok']);
    }

    public function test_nothing_is_refunded_automatically_when_a_mismatch_arrives(): void
    {
        $spy = Mockery::spy(PaymentGateway::class);
        $spy->shouldReceive('verifyWebhookSignature')->andReturn(true);
        $this->app->instance(PaymentGateway::class, $spy);

        [, , $log] = $this->mismatch();

        $this->assertSame('amount_mismatch', $log->outcome);
        $spy->shouldNotHaveReceived('refund');
    }

    public function test_the_refund_button_is_only_rendered_for_a_super_admin(): void
    {
        [, , $log] = $this->mismatch();

        Livewire::actingAs($this->makeSuperAdmin())->test(Health::class)->assertSee('Refund captured amount');

        $manager = $this->makeUserWithPermission('operations.manage', 'global');
        $this->grantPermission($manager, 'operations.view', 'global');
        Livewire::actingAs($manager)->test(Health::class)->assertDontSee('Refund captured amount');
    }

    // ============================== customer notice ==============================

    public function test_customer_is_told_the_payment_is_under_review_with_the_default_copy(): void
    {
        Notification::fake();

        [$user] = $this->mismatch();

        Notification::assertSentTo($user, PaymentUnderReviewNotification::class);
        $this->assertSame("Your payment is under review. We'll update you within 24 hours.", app(AmountMismatchService::class)->customerMessage());
    }

    public function test_customer_is_only_notified_once_per_gateway_payment(): void
    {
        Notification::fake();

        [$user, $payment, $log] = $this->mismatch(24999, 'pay_once');
        app(AmountMismatchService::class)->notifyCustomer($payment, 'pay_once');

        Notification::assertSentToTimes($user, PaymentUnderReviewNotification::class, 1);
    }

    public function test_admin_can_edit_the_customer_copy_and_the_customer_gets_the_new_text(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Health::class)
            ->set('mismatchCopy', 'We are checking your payment and will get back within a day.')
            ->call('saveMismatchCopy')
            ->assertHasNoErrors();

        $this->assertSame('We are checking your payment and will get back within a day.', Setting::get(AmountMismatchService::COPY_KEY));
        $this->assertNotNull(ActivityLog::where('description', 'Edited customer payment-under-review message')->first());

        Notification::fake();
        [$user] = $this->mismatch();

        Notification::assertSentTo($user, PaymentUnderReviewNotification::class, function ($n) use ($user) {
            return $n->toPush($user)['body'] === 'We are checking your payment and will get back within a day.';
        });
    }

    public function test_only_a_super_admin_can_edit_the_copy(): void
    {
        $manager = $this->makeUserWithPermission('operations.manage', 'global');
        $this->grantPermission($manager, 'operations.view', 'global');

        Livewire::actingAs($manager)->test(Health::class)
            ->set('mismatchCopy', 'Hacked message that is long enough.')
            ->call('saveMismatchCopy')
            ->assertForbidden();

        $this->assertNull(Setting::get(AmountMismatchService::COPY_KEY));
    }

    public function test_a_failing_notification_never_breaks_the_webhook(): void
    {
        $payment = Payment::create([
            'purpose' => 'parcel_order', 'amount' => 100, 'gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.Str::random(10), 'status' => 'pending',
        ]);

        // No customer resolvable for this payment: must still log the mismatch and answer 200.
        $payload = ['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
            'order_id' => $payment->gateway_order_id, 'id' => 'pay_x', 'amount' => 5, 'currency' => 'INR',
        ]]]];

        $this->postJson('/api/webhooks/razorpay', $payload, [
            'X-Razorpay-Signature' => hash_hmac('sha256', json_encode($payload), config('services.razorpay.webhook_secret')),
        ])->assertOk();

        $this->assertDatabaseHas('payment_webhook_logs', ['gateway_order_id' => $payment->gateway_order_id, 'outcome' => 'amount_mismatch']);
    }
}
