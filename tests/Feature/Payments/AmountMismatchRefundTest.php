<?php

namespace Tests\Feature\Payments;

use App\Contracts\PaymentGateway;
use App\Livewire\MismatchRefunds\Index as QueueIndex;
use App\Livewire\Operations\Health;
use App\Livewire\RefundControls\Manage as RefundControls;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\MismatchRefund;
use App\Models\Payment;
use App\Models\PaymentWebhookLog;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AdminOpsAlertNotification;
use App\Notifications\PaymentUnderReviewNotification;
use App\Notifications\RefundProcessedNotification;
use App\Services\Payments\AmountMismatchService;
use App\Services\Payments\MismatchRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Step 0c — mismatch refunds under the MANUAL MONEY ACTIONS approval model:
 * permission, franchise scope, per-level limits (null = cannot approve),
 * maker-checker, queue + once-per-level escalation, customer notices.
 * Unchanged guarantees: exact captured amount, reason required, idempotent,
 * gateway failure retryable, never automatic, Payment row never marked paid.
 */
class AmountMismatchRefundTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private const PERM = MismatchRefundService::PERMISSION;

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private static int $otherCountrySeq = 0;

    /** A franchise in its own country. The shared helpers keep separate country-code counters that can collide inside one test; 'Q?' codes never are used by either. */
    private function otherFranchise(): \App\Models\Franchise
    {
        $country = \App\Models\Country::create([
            'name' => 'Otherland', 'code' => 'Q'.base_convert((string) self::$otherCountrySeq++, 10, 36),
            'currency_code' => 'INR', 'default_timezone' => 'Asia/Kolkata', 'is_active' => true,
        ]);

        return $this->makeFranchise($this->makeCity($country));
    }

    private function postCapture(Payment $payment, int $capturedPaise, string $gatewayPaymentId): void
    {
        $payload = ['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
            'order_id' => $payment->gateway_order_id, 'id' => $gatewayPaymentId, 'amount' => $capturedPaise, 'currency' => 'INR',
        ]]]];

        $this->postJson('/api/webhooks/razorpay', $payload, [
            'X-Razorpay-Signature' => hash_hmac('sha256', json_encode($payload), config('services.razorpay.webhook_secret')),
        ])->assertOk();
    }

    /**
     * A ₹500 booking payment in $booking's franchise for which Razorpay captured ₹499.99.
     *
     * @return array{0: MismatchRefund, 1: Payment, 2: Booking}
     */
    private function bookingMismatch(?Booking $booking = null, int $capturedPaise = 49999, ?string $gatewayPaymentId = null): array
    {
        $booking ??= $this->makeBookingScenario()['booking'];
        $gatewayPaymentId ??= 'pay_'.Str::random(8);

        $payment = Payment::create([
            'booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => 500.00, 'gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.Str::random(10), 'status' => 'pending',
        ]);

        $this->postCapture($payment, $capturedPaise, $gatewayPaymentId);

        $row = MismatchRefund::where('gateway_payment_id', $gatewayPaymentId)->firstOrFail();

        return [$row, $payment, $booking];
    }

    private function gatewayExpecting(string $paymentId, float $amount, int $times = 1): void
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

    private function holder(string $scope, ?int $scopeId = null): User
    {
        return $this->makeUserWithPermission(self::PERM, $scope, $scopeId);
    }

    private function limits(?string $franchise, ?string $hq, ?string $dualAbove = null): void
    {
        foreach ([
            MismatchRefundService::FRANCHISE_LIMIT_KEY => $franchise,
            MismatchRefundService::HQ_LIMIT_KEY => $hq,
            MismatchRefundService::DUAL_APPROVAL_KEY => $dualAbove,
        ] as $key => $value) {
            $value === null ? Setting::clear($key, 'global', null) : Setting::set($key, $value);
        }
    }

    private function svc(): MismatchRefundService
    {
        return app(MismatchRefundService::class);
    }

    // ============================== queue row ==============================

    public function test_a_mismatch_joins_the_queue_with_exact_paise_and_the_bookings_franchise(): void
    {
        [$row, $payment, $booking] = $this->bookingMismatch(null, 24999);

        $this->assertSame(24999, $row->amount_paise);
        $this->assertSame($booking->franchise_id, $row->franchise_id);
        $this->assertSame(MismatchRefund::AWAITING_REQUEST, $row->status);
        $this->assertSame($payment->id, $row->payment_id);
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_a_payment_with_no_franchise_is_hq_only_and_old_logs_are_backfilled(): void
    {
        $user = $this->makeCustomer();
        $payment = Payment::create([
            'purpose' => 'wallet_topup', 'amount' => 250, 'gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.Str::random(10), 'status' => 'pending', 'user_id' => $user->id,
        ]);
        $log = PaymentWebhookLog::create([
            'gateway' => 'razorpay', 'event' => 'payment.captured', 'gateway_order_id' => $payment->gateway_order_id,
            'gateway_payment_id' => 'pay_old', 'payment_id' => $payment->id, 'signature_valid' => true, 'processed' => false,
            'outcome' => 'amount_mismatch', 'created_at' => now(),
            'payload' => ['payload' => ['payment' => ['entity' => ['id' => 'pay_old', 'amount' => 100]]]],
        ]);

        $this->svc()->syncFromLogs();
        $this->svc()->syncFromLogs(); // idempotent

        $row = MismatchRefund::where('payment_webhook_log_id', $log->id)->sole();
        $this->assertNull($row->franchise_id);
        $this->assertSame(100, $row->amount_paise);

        $franchiseHolder = $this->holder('franchise', $this->makeBookingScenario()['franchise']->id);
        $this->assertNull($this->svc()->availableAction($row, $franchiseHolder), 'franchise holders never act on an HQ-only row');
        $this->assertSame('request', $this->svc()->availableAction($row, $this->holder('global')));
    }

    // ============================== permission ==============================

    public function test_a_permission_holder_can_refund_exactly_the_captured_amount_and_it_is_audit_logged(): void
    {
        [$row, $payment] = $this->bookingMismatch();
        $this->limits(null, '1000');
        $hq = $this->holder('global');
        $this->gatewayExpecting($row->gateway_payment_id, 499.99);

        $result = $this->svc()->request($row, $hq, 'Customer paid ₹499.99 by mistake');

        $this->assertTrue($result['ok'], $result['message']);
        $row->refresh();
        $this->assertSame(MismatchRefund::REFUNDED, $row->status);
        $this->assertSame('rfnd_1', $row->gateway_refund_id);
        $this->assertSame('amount_mismatch_refunded', PaymentWebhookLog::find($row->payment_webhook_log_id)->outcome);
        $this->assertSame('pending', $payment->fresh()->status, 'the Payment row is never marked paid by a refund');

        $audit = ActivityLog::where('description', 'like', 'Refunded mismatched payment%')->sole();
        $this->assertSame($hq->id, $audit->causer_id);
        $this->assertSame(49999, $audit->properties['amount_paise']);
        $this->assertSame('Customer paid ₹499.99 by mistake', $audit->properties['reason']);
        $this->assertNotNull(ActivityLog::where('description', 'like', 'Mismatch refund requested%')->first());
    }

    public function test_a_non_holder_is_refused_server_side_and_never_reaches_the_gateway(): void
    {
        [$row] = $this->bookingMismatch();
        $this->limits('1000', '1000');
        $nobody = $this->makeUserWithNoPermissions();
        $this->gatewayNeverCalled();

        $this->assertFalse($this->svc()->request($row, $nobody, 'reason')['ok']);
        $this->assertSame(MismatchRefund::AWAITING_REQUEST, $row->fresh()->status);

        Livewire::actingAs($nobody)->test(QueueIndex::class)->assertForbidden();
    }

    public function test_the_permission_is_seeded_to_super_admin_only(): void
    {
        $permission = \App\Models\Permission::where('slug', self::PERM)->firstOrFail();

        $this->assertSame(['super_admin'], $permission->roles()->pluck('slug')->all());
    }

    public function test_reason_is_required(): void
    {
        [$row] = $this->bookingMismatch();
        $this->gatewayNeverCalled();

        $this->assertFalse($this->svc()->request($row, $this->makeSuperAdmin(), '   ')['ok']);

        Livewire::actingAs($this->makeSuperAdmin())->test(QueueIndex::class)
            ->call('startAction', $row->id, 'request')
            ->set('reason', '')
            ->call('submitAction')
            ->assertHasErrors('reason');

        $this->assertSame(MismatchRefund::AWAITING_REQUEST, $row->fresh()->status);
    }

    // ============================== scope ==============================

    public function test_a_franchise_holder_acts_only_inside_their_franchise(): void
    {
        [$row, , $booking] = $this->bookingMismatch();
        $this->limits('1000', '1000');
        $otherFranchise = $this->otherFranchise();

        $inside = $this->holder('franchise', $booking->franchise_id);
        $outside = $this->holder('franchise', $otherFranchise->id);

        $this->gatewayNeverCalled();
        $this->assertFalse($this->svc()->request($row, $outside, 'reason')['ok']);
        $this->assertSame(MismatchRefund::AWAITING_REQUEST, $row->fresh()->status);

        Livewire::actingAs($inside)->test(QueueIndex::class)->assertSee($row->gateway_payment_id);

        // The outside holder cannot even see the row: a Livewire call on it is "not found" (404), not a 403.
        $component = Livewire::actingAs($outside)->test(QueueIndex::class)->assertDontSee($row->gateway_payment_id);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $component->call('startAction', $row->id, 'request');

    }

    public function test_a_franchise_holder_inside_their_franchise_can_refund_within_their_limit(): void
    {
        [$row, , $booking] = $this->bookingMismatch();
        $this->limits('1000', '5000');
        $this->gatewayExpecting($row->gateway_payment_id, 499.99);

        $this->assertTrue($this->svc()->request($row, $this->holder('franchise', $booking->franchise_id), 'reason')['ok']);
        $this->assertSame(MismatchRefund::REFUNDED, $row->fresh()->status);
    }

    // ============================== limits ==============================

    public function test_each_level_is_held_to_its_own_limit(): void
    {
        [$row, , $booking] = $this->bookingMismatch(); // ₹499.99
        $this->limits('400', '450');
        $franchise = $this->holder('franchise', $booking->franchise_id);
        $hq = $this->holder('global');
        $super = $this->makeSuperAdmin();

        // Franchise requester is over their ₹400 limit: recorded, not executed.
        $this->gatewayNeverCalled();
        $this->assertTrue($this->svc()->request($row, $franchise, 'over my limit')['ok']);
        $this->assertSame(MismatchRefund::AWAITING_APPROVAL, $row->fresh()->status);

        // Neither the franchise holder (different row owner aside) nor the HQ holder (₹450) may approve ₹499.99.
        $otherFranchiseHolder = $this->holder('franchise', $booking->franchise_id);
        $this->assertFalse($this->svc()->approve($row, $otherFranchiseHolder, 'ok')['ok']);
        $this->assertFalse($this->svc()->approve($row, $hq, 'ok')['ok']);
        $this->assertNull($this->svc()->availableAction($row->fresh(), $hq));

        // Above the HQ limit it is Super Admin only.
        $this->gatewayExpecting($row->gateway_payment_id, 499.99);
        $this->assertTrue($this->svc()->approve($row, $super, 'approved at HQ+')['ok']);
        $this->assertSame(MismatchRefund::REFUNDED, $row->fresh()->status);
    }

    public function test_a_null_limit_means_that_level_cannot_approve(): void
    {
        [$row, , $booking] = $this->bookingMismatch();
        $this->limits(null, null);
        $franchise = $this->holder('franchise', $booking->franchise_id);
        $hq = $this->holder('global');

        $this->gatewayNeverCalled();

        // Both may still REQUEST, but nothing executes.
        $this->assertTrue($this->svc()->request($row, $franchise, 'please')['ok']);
        $this->assertSame(MismatchRefund::AWAITING_APPROVAL, $row->fresh()->status);

        $this->assertFalse($this->svc()->approve($row, $hq, 'ok')['ok']);
        $this->assertFalse($this->svc()->approve($row, $this->holder('franchise', $booking->franchise_id), 'ok')['ok']);
        $this->assertSame(MismatchRefund::AWAITING_APPROVAL, $row->fresh()->status);
    }

    public function test_hq_with_no_limit_cannot_execute_its_own_request_but_super_admin_can(): void
    {
        [$row] = $this->bookingMismatch();
        $this->limits(null, null);

        $this->gatewayExpecting($row->gateway_payment_id, 499.99);
        $this->assertTrue($this->svc()->request($row, $this->holder('global'), 'asking')['ok']);
        $this->assertSame(MismatchRefund::AWAITING_APPROVAL, $row->fresh()->status);

        $this->assertTrue($this->svc()->approve($row, $this->makeSuperAdmin(), 'ok')['ok']);
    }

    // ============================== maker-checker ==============================

    public function test_above_the_threshold_the_requester_can_never_approve_their_own_request(): void
    {
        [$row] = $this->bookingMismatch(); // ₹499.99
        $this->limits('1000', '1000', '100'); // dual approval above ₹100
        $maker = $this->holder('global');
        $checker = $this->holder('global');

        $this->gatewayNeverCalled();
        $this->assertTrue($this->svc()->request($row, $maker, 'needs a second pair of eyes')['ok']);
        $this->assertSame(MismatchRefund::AWAITING_APPROVAL, $row->fresh()->status, 'within the maker\'s limit but above the dual threshold');

        $self = $this->svc()->approve($row, $maker, 'approving my own');
        $this->assertFalse($self['ok']);
        $this->assertStringContainsString('different user', $self['message']);
        $this->assertNull($this->svc()->availableAction($row->fresh(), $maker));

        $this->gatewayExpecting($row->gateway_payment_id, 499.99);
        $this->assertTrue($this->svc()->approve($row, $checker, 'second approval')['ok']);

        $row->refresh();
        $this->assertSame($maker->id, $row->requested_by_id);
        $this->assertSame($checker->id, $row->approved_by_id);
        $this->assertNotNull(ActivityLog::where('description', 'like', 'Mismatch refund requested%')->first());
        $this->assertNotNull(ActivityLog::where('description', 'like', 'Mismatch refund approved%')->first());
    }

    public function test_super_admin_cannot_approve_their_own_request_either(): void
    {
        [$row] = $this->bookingMismatch();
        $this->limits('1000', '1000', '100');
        $super = $this->makeSuperAdmin();

        $this->gatewayNeverCalled();
        $this->assertTrue($this->svc()->request($row, $super, 'mine')['ok']);
        $this->assertFalse($this->svc()->approve($row, $super, 'mine again')['ok']);
        $this->assertSame(MismatchRefund::AWAITING_APPROVAL, $row->fresh()->status);
    }

    public function test_a_null_threshold_turns_maker_checker_off(): void
    {
        [$row] = $this->bookingMismatch();
        $this->limits('1000', '1000', null);

        $this->gatewayExpecting($row->gateway_payment_id, 499.99);
        $this->assertTrue($this->svc()->request($row, $this->holder('global'), 'single step')['ok']);
        $this->assertSame(MismatchRefund::REFUNDED, $row->fresh()->status);
    }

    // ============================== idempotency & failure ==============================

    public function test_a_refund_happens_once_a_second_attempt_never_reaches_the_gateway(): void
    {
        [$row] = $this->bookingMismatch();
        $this->limits('1000', '1000');
        $hq = $this->holder('global');
        $this->gatewayExpecting($row->gateway_payment_id, 499.99, 1);

        $this->assertTrue($this->svc()->request($row, $hq, 'first')['ok']);
        $this->assertFalse($this->svc()->request($row->fresh(), $hq, 'second')['ok']);
        $this->assertFalse($this->svc()->approve($row->fresh(), $this->makeSuperAdmin(), 'third')['ok']);
        $this->assertFalse($this->svc()->retry($row->fresh(), $this->makeSuperAdmin())['ok']);
    }

    public function test_a_redelivered_event_for_a_refunded_payment_creates_no_second_refund(): void
    {
        [$row, $payment] = $this->bookingMismatch(null, 49999, 'pay_dup');
        $this->limits('1000', '1000');
        $this->gatewayExpecting('pay_dup', 499.99, 1);
        $this->assertTrue($this->svc()->request($row, $this->holder('global'), 'first')['ok']);

        $dup = PaymentWebhookLog::create([
            'gateway' => 'razorpay', 'event' => 'payment.captured', 'gateway_order_id' => $payment->gateway_order_id,
            'gateway_payment_id' => 'pay_dup', 'payment_id' => $payment->id, 'signature_valid' => true, 'processed' => false,
            'outcome' => 'amount_mismatch', 'payload' => $row->webhookLog->payload, 'created_at' => now(),
        ]);

        $this->assertSame($row->id, $this->svc()->ensureForLog($dup)->id, 'still one row per gateway payment');
        $this->assertSame(1, MismatchRefund::where('gateway_payment_id', 'pay_dup')->count());
        $this->assertSame(MismatchRefund::REFUNDED, $row->fresh()->status);
    }

    public function test_a_gateway_failure_changes_nothing_is_logged_and_can_be_retried(): void
    {
        [$row] = $this->bookingMismatch();
        $this->limits('1000', '1000');
        $hq = $this->holder('global');

        $failing = Mockery::mock(PaymentGateway::class);
        $failing->shouldReceive('refund')->once()->andThrow(new \RuntimeException('gateway down'));
        $this->app->instance(PaymentGateway::class, $failing);

        Notification::fake();
        $result = $this->svc()->request($row, $hq, 'try');

        $this->assertFalse($result['ok']);
        $row->refresh();
        $this->assertSame(MismatchRefund::FAILED, $row->status);
        $this->assertNull($row->refunded_at);
        $this->assertNull($row->refund_notice_sent_at);
        $this->assertSame('amount_mismatch', PaymentWebhookLog::find($row->payment_webhook_log_id)->outcome);
        $this->assertNotNull(ActivityLog::where('description', 'like', 'Mismatch refund FAILED%')->first());
        Notification::assertNotSentTo($this->customerOf($row), RefundProcessedNotification::class);

        $this->gatewayExpecting($row->gateway_payment_id, 499.99);
        $this->assertSame('retry', $this->svc()->availableAction($row, $hq));
        $this->assertTrue($this->svc()->retry($row, $hq)['ok']);
        $this->assertSame(MismatchRefund::REFUNDED, $row->fresh()->status);
        Notification::assertSentToTimes($this->customerOf($row), RefundProcessedNotification::class, 1);
    }

    public function test_nothing_is_refunded_automatically_when_a_mismatch_arrives(): void
    {
        $spy = Mockery::spy(PaymentGateway::class);
        $spy->shouldReceive('verifyWebhookSignature')->andReturn(true);
        $this->app->instance(PaymentGateway::class, $spy);

        [$row] = $this->bookingMismatch();

        $this->assertSame(MismatchRefund::AWAITING_REQUEST, $row->status);
        $spy->shouldNotHaveReceived('refund');
    }

    // ============================== reject ==============================

    /** A ₹499.99 row already requested by $maker (limits ₹1000, maker-checker on) and awaiting approval. */
    private function awaitingApproval(): array
    {
        [$row, , $booking] = $this->bookingMismatch();
        $this->limits('1000', '1000', '100');
        $maker = $this->holder('global');
        $this->assertTrue($this->svc()->request($row, $maker, 'please refund this')['ok']);
        $this->assertSame(MismatchRefund::AWAITING_APPROVAL, $row->fresh()->status);

        return [$row->fresh(), $maker, $booking];
    }

    public function test_reject_returns_the_row_to_awaiting_request_and_keeps_the_requester_in_the_audit_log(): void
    {
        [$row, $maker] = $this->awaitingApproval();
        $checker = $this->holder('global');
        $this->gatewayNeverCalled();
        Notification::fake(); // only what the rejection itself sends counts (the under-review notice already went out on mismatch)

        $result = $this->svc()->reject($row, $checker, 'amount looks genuine, ask the customer first');

        $this->assertTrue($result['ok'], $result['message']);
        $row->refresh();
        $this->assertSame(MismatchRefund::AWAITING_REQUEST, $row->status);
        $this->assertNull($row->requested_by_id);
        $this->assertNull($row->request_reason);
        $this->assertNotNull($row->rejected_at);

        $audit = ActivityLog::where('description', 'like', 'Mismatch refund rejected%')->sole();
        $this->assertSame($checker->id, $audit->causer_id);
        $this->assertSame($maker->id, $audit->properties['requested_by_id']);
        $this->assertSame('please refund this', $audit->properties['request_reason']);
        $this->assertSame('amount looks genuine, ask the customer first', $audit->properties['rejection_reason']);

        Notification::assertNotSentTo($this->customerOf($row), RefundProcessedNotification::class);
        Notification::assertNotSentTo($this->customerOf($row), PaymentUnderReviewNotification::class);
    }

    public function test_the_requester_cannot_reject_their_own_request(): void
    {
        [$row, $maker] = $this->awaitingApproval();

        $result = $this->svc()->reject($row, $maker, 'changed my mind');

        $this->assertFalse($result['ok']);
        $this->assertSame(MismatchRefund::AWAITING_APPROVAL, $row->fresh()->status);
        $this->assertFalse($this->svc()->canReject($row, $maker));
    }

    public function test_an_out_of_scope_or_over_limit_user_cannot_reject(): void
    {
        [$row, , $booking] = $this->awaitingApproval();
        $outsider = $this->holder('franchise', $this->otherFranchise()->id);
        $insideFranchise = $this->holder('franchise', $booking->franchise_id);

        // franchise limit ₹100 < ₹499.99: the in-franchise holder is over their limit
        $this->limits('100', '1000', '100');

        $this->assertFalse($this->svc()->reject($row, $this->makeUserWithNoPermissions(), 'no')['ok']);
        $this->assertFalse($this->svc()->reject($row, $outsider, 'no')['ok'], 'out of scope');
        $this->assertFalse($this->svc()->reject($row, $insideFranchise, 'no')['ok'], 'over limit');
        $this->assertFalse($this->svc()->canReject($row, $insideFranchise));
        $this->assertSame(MismatchRefund::AWAITING_APPROVAL, $row->fresh()->status);

        // The outside holder cannot reach the row at all through the queue screen.
        $component = Livewire::actingAs($outsider)->test(QueueIndex::class);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $component->call('startAction', $row->id, 'reject');
    }

    public function test_reject_needs_a_reason_and_an_awaiting_approval_row(): void
    {
        [$row] = $this->awaitingApproval();
        $checker = $this->holder('global');

        $this->assertFalse($this->svc()->reject($row, $checker, '   ')['ok']);
        $this->assertSame(MismatchRefund::AWAITING_APPROVAL, $row->fresh()->status);

        $this->assertTrue($this->svc()->reject($row, $checker, 'first rejection')['ok']);
        $this->assertFalse($this->svc()->reject($row->fresh(), $checker, 'again')['ok'], 'already back at awaiting_request');
    }

    public function test_a_fresh_request_after_a_rejection_works_and_can_complete(): void
    {
        [$row, $maker] = $this->awaitingApproval();
        $checker = $this->holder('global');
        $this->assertTrue($this->svc()->reject($row, $checker, 'not yet')['ok']);

        $newMaker = $this->holder('global');
        $this->assertTrue($this->svc()->request($row->fresh(), $newMaker, 'customer confirmed the double payment')['ok']);
        $this->assertSame(MismatchRefund::AWAITING_APPROVAL, $row->fresh()->status);
        $this->assertSame($newMaker->id, $row->fresh()->requested_by_id);

        // The earlier requester is now free to approve the new request, because they are no longer the requester.
        $this->gatewayExpecting($row->gateway_payment_id, 499.99);
        $this->assertTrue($this->svc()->approve($row->fresh(), $maker, 'verified')['ok']);
        $this->assertSame(MismatchRefund::REFUNDED, $row->fresh()->status);
    }

    public function test_rejection_restarts_the_escalation_clock(): void
    {
        Setting::set('notifications.channels', 'mail,push');
        Notification::fake();

        [$row, $maker, $booking] = $this->awaitingApproval();
        $this->limits('1000', '5000', '100');
        Setting::set(MismatchRefundService::ESCALATE_HOURS_KEY, '2');
        $hq = $this->pushAdmin($this->holder('global'));
        $super = $this->pushAdmin($this->makeSuperAdmin());

        // 3h in: escalated to HQ (handler = franchise level has no holder here, row handled at franchise level).
        Carbon::setTestNow(now()->addHours(3));
        $this->assertSame(1, $this->svc()->escalateOverdue());
        $this->assertSame(2, $row->fresh()->escalation_level);

        // Rejected now: progress resets and the clock restarts from this moment.
        $this->assertTrue($this->svc()->reject($row->fresh(), $this->holder('global'), 'start over')['ok']);
        $this->assertSame(0, $row->fresh()->escalation_level);
        $this->assertNull($row->fresh()->last_escalated_at);

        // 1h after the rejection: still inside the 2h window, although the row is 4h old overall.
        Carbon::setTestNow(now()->addHour());
        $this->assertSame(0, $this->svc()->escalateOverdue());

        // 3h after the rejection: escalates again from the restarted clock.
        Carbon::setTestNow(now()->addHours(2));
        $this->assertSame(1, $this->svc()->escalateOverdue());
        $this->assertSame(2, $row->fresh()->escalation_level);
    }

    public function test_reject_works_end_to_end_through_the_queue_screen(): void
    {
        [$row] = $this->awaitingApproval();
        $checker = $this->holder('global');

        Livewire::actingAs($checker)->test(QueueIndex::class)
            ->assertSee('Reject')
            ->call('startAction', $row->id, 'reject')
            ->set('reason', 'wrong customer')
            ->call('submitAction')
            ->assertSet('flashType', 'success');

        $this->assertSame(MismatchRefund::AWAITING_REQUEST, $row->fresh()->status);
    }

    // ============================== escalation ==============================

    private function pushAdmin(User $user): User
    {
        $user->forceFill(['push_ops_alerts' => true, 'fcm_token' => 'tok-'.Str::random(6)])->save();

        return $user;
    }

    public function test_escalation_alerts_the_next_level_once_per_level_after_the_set_hours(): void
    {
        Setting::set('notifications.channels', 'mail,push');
        Notification::fake();

        [$row, , $booking] = $this->bookingMismatch(); // ₹499.99
        $this->limits('1000', '5000');
        Setting::set(MismatchRefundService::ESCALATE_HOURS_KEY, '2');

        $franchise = $this->pushAdmin($this->holder('franchise', $booking->franchise_id));
        $hq = $this->pushAdmin($this->holder('global'));
        $super = $this->pushAdmin($this->makeSuperAdmin());

        // Handled at franchise level, nothing yet.
        $this->assertSame(0, $this->svc()->escalateOverdue());

        Carbon::setTestNow(now()->addHours(3));
        $this->assertSame(1, $this->svc()->escalateOverdue());
        Notification::assertSentTo($hq, AdminOpsAlertNotification::class);
        Notification::assertNotSentTo($franchise, AdminOpsAlertNotification::class);
        Notification::assertNotSentTo($super, AdminOpsAlertNotification::class);
        $this->assertSame(2, $row->fresh()->escalation_level);

        // Same age again: no duplicate.
        $this->assertSame(0, $this->svc()->escalateOverdue());

        Carbon::setTestNow(now()->addHours(2));
        $this->assertSame(1, $this->svc()->escalateOverdue());
        Notification::assertSentTo($super, AdminOpsAlertNotification::class);
        $this->assertSame(3, $row->fresh()->escalation_level);

        // Reached Super Admin: nothing further.
        Carbon::setTestNow(now()->addHours(10));
        $this->assertSame(0, $this->svc()->escalateOverdue());
    }

    public function test_escalation_is_off_when_unset_and_skips_resolved_rows(): void
    {
        Setting::set('notifications.channels', 'mail,push');
        Notification::fake();

        [$row, , $booking] = $this->bookingMismatch();
        $this->limits('1000', '5000');
        $hq = $this->pushAdmin($this->holder('global'));

        Carbon::setTestNow(now()->addDays(3));
        $this->assertSame(0, $this->svc()->escalateOverdue(), 'null hours = off');
        Notification::assertNotSentTo($hq, AdminOpsAlertNotification::class);

        Setting::set(MismatchRefundService::ESCALATE_HOURS_KEY, '2');
        $row->update(['status' => MismatchRefund::REFUNDED]);
        $this->assertSame(0, $this->svc()->escalateOverdue(), 'refunded rows never escalate');
    }

    public function test_the_escalation_command_runs(): void
    {
        $this->artisan('refunds:escalate-mismatch')->assertSuccessful();
    }

    // ============================== refund notice ==============================

    private function customerOf(MismatchRefund $row): User
    {
        return app(AmountMismatchService::class)->customerFor($row->payment);
    }

    public function test_the_refund_notice_goes_once_with_the_amount_filled_in(): void
    {
        Notification::fake();
        [$row] = $this->bookingMismatch();
        $this->limits('1000', '1000');
        $this->gatewayExpecting($row->gateway_payment_id, 499.99);

        $this->assertTrue($this->svc()->request($row, $this->holder('global'), 'go')['ok']);

        $customer = $this->customerOf($row);
        Notification::assertSentToTimes($customer, RefundProcessedNotification::class, 1);
        Notification::assertSentTo($customer, RefundProcessedNotification::class, fn ($n) => $n->toPush($customer)['body']
            === 'Your refund of ₹499.99 has been processed. It will reach your account in 5–7 working days.');
        $this->assertNotNull($row->fresh()->refund_notice_sent_at);
    }

    // ============================== under-review notice ==============================

    public function test_customer_is_told_the_payment_is_under_review_once_per_gateway_payment(): void
    {
        Notification::fake();

        [$row, $payment] = $this->bookingMismatch(null, 49999, 'pay_once');
        app(AmountMismatchService::class)->notifyCustomer($payment, 'pay_once');

        $customer = $this->customerOf($row);
        Notification::assertSentToTimes($customer, PaymentUnderReviewNotification::class, 1);
        $this->assertSame("Your payment is under review. We'll update you within 24 hours.", app(AmountMismatchService::class)->customerMessage());
    }

    public function test_a_failing_notification_never_breaks_the_webhook(): void
    {
        $payment = Payment::create([
            'purpose' => 'parcel_order', 'amount' => 100, 'gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.Str::random(10), 'status' => 'pending',
        ]);

        $this->postCapture($payment, 5, 'pay_x'); // no customer resolvable: still 200

        $this->assertDatabaseHas('payment_webhook_logs', ['gateway_order_id' => $payment->gateway_order_id, 'outcome' => 'amount_mismatch']);
        $this->assertDatabaseHas('mismatch_refunds', ['gateway_payment_id' => 'pay_x', 'amount_paise' => 5]);
    }

    // ============================== Refund Controls ==============================

    public function test_super_admin_saves_every_control_and_each_change_is_audit_logged(): void
    {
        Livewire::actingAs($this->makeSuperAdmin())->test(RefundControls::class)
            ->set('franchiseLimit', '2000')
            ->set('hqLimit', '10000')
            ->set('dualApprovalAbove', '5000')
            ->set('escalateAfterHours', '6')
            ->set('noticeUnderReview', 'We are checking your payment and will reply within a day.')
            ->set('noticeRefunded', 'Refunded ₹[amount]; it should reach you in 5-7 working days.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2000', Setting::get(MismatchRefundService::FRANCHISE_LIMIT_KEY));
        $this->assertSame('10000', Setting::get(MismatchRefundService::HQ_LIMIT_KEY));
        $this->assertSame('5000', Setting::get(MismatchRefundService::DUAL_APPROVAL_KEY));
        $this->assertSame('6', Setting::get(MismatchRefundService::ESCALATE_HOURS_KEY));
        $this->assertSame('We are checking your payment and will reply within a day.', app(AmountMismatchService::class)->customerMessage());
        $this->assertSame('Refunded ₹12.50; it should reach you in 5-7 working days.', app(AmountMismatchService::class)->refundedMessage(12.5));
        $this->assertSame(6, ActivityLog::where('description', 'like', 'Setting refund.mismatch.%')->count());
    }

    public function test_blank_clears_a_limit_back_to_not_configured(): void
    {
        $this->limits('100', '200', '50');
        Setting::set(MismatchRefundService::ESCALATE_HOURS_KEY, '4');

        Livewire::actingAs($this->makeSuperAdmin())->test(RefundControls::class)
            ->set('franchiseLimit', '')->set('hqLimit', '')->set('dualApprovalAbove', '')->set('escalateAfterHours', '')
            ->call('save')->assertHasNoErrors();

        $this->assertNull(Setting::get(MismatchRefundService::FRANCHISE_LIMIT_KEY));
        $this->assertNull($this->svc()->limitPaise(MismatchRefundService::LEVEL_FRANCHISE));
        $this->assertNull($this->svc()->limitPaise(MismatchRefundService::LEVEL_HQ));
        $this->assertNull($this->svc()->escalateAfterHours());
    }

    public function test_only_a_super_admin_can_open_or_save_refund_controls(): void
    {
        $holder = $this->holder('global');

        Livewire::actingAs($holder)->test(RefundControls::class)->assertForbidden();
        $this->assertNull(Setting::get(MismatchRefundService::HQ_LIMIT_KEY));
    }

    public function test_controls_validate_their_values(): void
    {
        $c = Livewire::actingAs($this->makeSuperAdmin())->test(RefundControls::class);

        $c->set('franchiseLimit', '900')->set('hqLimit', '100')->call('save')->assertHasErrors('franchiseLimit');
        $c->set('franchiseLimit', '-5')->set('hqLimit', '')->call('save')->assertHasErrors('franchiseLimit');
        $c->set('franchiseLimit', '')->set('escalateAfterHours', '0')->call('save')->assertHasErrors('escalateAfterHours');
        $c->set('escalateAfterHours', '')->set('noticeRefunded', 'Your refund has been processed, thanks.')->call('save')->assertHasErrors('noticeRefunded');
    }

    // ============================== Operations link ==============================

    public function test_the_operations_log_links_to_the_queue_only_for_permission_holders(): void
    {
        $this->bookingMismatch();

        Livewire::actingAs($this->makeSuperAdmin())->test(Health::class)->assertSee('Open refund queue');

        $viewer = $this->makeUserWithPermission('operations.view', 'global');
        Livewire::actingAs($viewer)->test(Health::class)->assertDontSee('Open refund queue');
    }
}
