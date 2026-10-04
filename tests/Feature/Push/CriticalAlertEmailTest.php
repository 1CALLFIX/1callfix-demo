<?php

namespace Tests\Feature\Push;

use App\Contracts\PaymentGateway;
use App\Livewire\AlertEmails\Manage as AlertEmails;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\MismatchRefund;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AdminOpsAlertNotification;
use App\Services\AdminOpsAlertService;
use App\Services\Payments\MismatchRefundService;
use App\Services\Payments\RefundAlertingGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Step 0d — the critical admin alerts are also emailed: payment_amount_mismatch,
 * refund failed (any path), mismatch-refund escalation, cancel payout failed,
 * dispatch job failure. Same scoping as the push alert, per-type Super Admin
 * switch (default on), never dependent on push being enabled or an fcm_token,
 * push behaviour unchanged.
 */
class CriticalAlertEmailTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('notifications.channels', 'mail,push');
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

    private function withEmail(User $user): User
    {
        $user->forceFill(['email' => 'admin-'.Str::random(8).'@example.test'])->save();

        return $user;
    }

    private function superAdmin(): User
    {
        return $this->withEmail($this->makeSuperAdmin());
    }

    private function operator(int $franchiseId): User
    {
        return $this->withEmail($this->makeUserWithPermission('operations.view', 'franchise', $franchiseId));
    }

    private function pushOptedIn(User $user): User
    {
        $user->forceFill(['push_ops_alerts' => true, 'fcm_token' => 'tok-'.Str::random(6)])->save();

        return $user;
    }

    private function mailed($n, $channels): bool
    {
        return $n instanceof AdminOpsAlertNotification && $channels === ['mail'];
    }

    private function pushed($n, $channels): bool
    {
        return $n instanceof AdminOpsAlertNotification && in_array(\App\Notifications\Channels\PushChannel::class, $channels, true) && ! in_array('mail', $channels, true);
    }

    private function svc(): AdminOpsAlertService
    {
        return app(AdminOpsAlertService::class);
    }

    // ============================== payment_amount_mismatch ==============================

    public function test_mismatch_email_goes_to_super_admin_and_in_scope_refund_holders_only(): void
    {
        Notification::fake();
        $booking = $this->makeBookingScenario()['booking'];
        $payment = Payment::create(['booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'gateway_order_id' => 'o_1', 'status' => 'pending']);

        $super = $this->superAdmin();
        $inScope = $this->withEmail($this->makeUserWithPermission(MismatchRefundService::PERMISSION, 'franchise', $booking->franchise_id));
        $outOfScope = $this->withEmail($this->makeUserWithPermission(MismatchRefundService::PERMISSION, 'franchise', $this->otherFranchise()->id));
        $opsOnly = $this->operator($booking->franchise_id); // can see operations, cannot act on refunds

        $this->svc()->paymentAmountMismatch($payment);

        Notification::assertSentTo($super, AdminOpsAlertNotification::class, fn ($n, $c) => $this->mailed($n, $c));
        Notification::assertSentTo($inScope, AdminOpsAlertNotification::class, fn ($n, $c) => $this->mailed($n, $c));
        Notification::assertNotSentTo($outOfScope, AdminOpsAlertNotification::class);
        Notification::assertNotSentTo($opsOnly, AdminOpsAlertNotification::class);
    }

    public function test_the_email_does_not_depend_on_push_opt_in_or_a_token(): void
    {
        Notification::fake();
        Setting::set('notifications.channels', 'mail'); // push switched off platform-wide
        $booking = $this->makeBookingScenario()['booking'];
        $payment = Payment::create(['booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'gateway_order_id' => 'o_2', 'status' => 'pending']);
        $super = $this->superAdmin(); // push_ops_alerts false, no fcm_token

        $this->svc()->paymentAmountMismatch($payment);

        Notification::assertSentTo($super, AdminOpsAlertNotification::class, fn ($n, $c) => $this->mailed($n, $c));
    }

    public function test_push_is_unchanged_and_the_email_is_a_separate_notification(): void
    {
        Notification::fake();
        $booking = $this->makeBookingScenario()['booking'];
        $payment = Payment::create(['booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'gateway_order_id' => 'o_3', 'status' => 'pending']);
        $super = $this->pushOptedIn($this->superAdmin());

        $this->svc()->paymentAmountMismatch($payment);

        Notification::assertSentTo($super, AdminOpsAlertNotification::class, fn ($n, $c) => $this->pushed($n, $c));
        Notification::assertSentTo($super, AdminOpsAlertNotification::class, fn ($n, $c) => $this->mailed($n, $c));
    }

    public function test_the_email_is_not_sent_when_the_super_admin_turns_the_type_off_but_push_still_is(): void
    {
        Notification::fake();
        Setting::set('alerts.email.payment_amount_mismatch', '0');
        $booking = $this->makeBookingScenario()['booking'];
        $payment = Payment::create(['booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'gateway_order_id' => 'o_4', 'status' => 'pending']);
        $super = $this->pushOptedIn($this->superAdmin());

        $this->svc()->paymentAmountMismatch($payment);

        Notification::assertNotSentTo($super, AdminOpsAlertNotification::class, fn ($n, $c) => $this->mailed($n, $c));
        Notification::assertSentTo($super, AdminOpsAlertNotification::class, fn ($n, $c) => $this->pushed($n, $c));
    }

    public function test_suspended_and_email_less_admins_are_skipped(): void
    {
        Notification::fake();
        $booking = $this->makeBookingScenario()['booking'];
        $payment = Payment::create(['booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'gateway_order_id' => 'o_5', 'status' => 'pending']);

        $suspended = $this->superAdmin();
        $suspended->forceFill(['status' => 'suspended'])->save();
        $noEmail = $this->makeSuperAdmin(); // no email at all

        $this->svc()->paymentAmountMismatch($payment);

        Notification::assertNotSentTo($suspended, AdminOpsAlertNotification::class);
        Notification::assertNotSentTo($noEmail, AdminOpsAlertNotification::class);
    }

    // ============================== dispatch job failure ==============================

    public function test_dispatch_job_failure_email_is_scoped_but_a_plain_escalation_is_push_only(): void
    {
        Notification::fake();
        $booking = $this->makeBookingScenario()['booking'];
        $same = $this->operator($booking->franchise_id);
        $other = $this->operator($this->otherFranchise()->id);
        $super = $this->superAdmin();

        $this->svc()->dispatchEscalation($booking, jobFailed: true);

        Notification::assertSentTo($same, AdminOpsAlertNotification::class, fn ($n, $c) => $this->mailed($n, $c));
        Notification::assertSentTo($super, AdminOpsAlertNotification::class, fn ($n, $c) => $this->mailed($n, $c));
        Notification::assertNotSentTo($other, AdminOpsAlertNotification::class);

        Notification::fake();
        $this->svc()->dispatchEscalation($booking, jobFailed: false); // ordinary T+5: not one of the five

        Notification::assertNotSentTo($same, AdminOpsAlertNotification::class, fn ($n, $c) => $this->mailed($n, $c));
    }

    // ============================== cancel payout failed ==============================

    public function test_cancel_payout_failed_is_emailed_but_other_cancellation_events_are_not(): void
    {
        Notification::fake();
        $booking = $this->makeBookingScenario()['booking'];
        $same = $this->operator($booking->franchise_id);

        $this->svc()->cancellationEvent('cancel_payout_failed', $booking);
        Notification::assertSentTo($same, AdminOpsAlertNotification::class, fn ($n, $c) => $this->mailed($n, $c));

        Notification::fake();
        $this->svc()->cancellationEvent('interim_dispute', $booking);
        $this->svc()->cancellationEvent('cancel_charge_unpaid', $booking);
        Notification::assertNotSentTo($same, AdminOpsAlertNotification::class);
    }

    // ============================== refund failed ==============================

    public function test_an_auto_cancel_refund_failure_is_emailed_to_the_scoped_operators(): void
    {
        Notification::fake();
        $booking = $this->makeBookingScenario()['booking'];
        $same = $this->operator($booking->franchise_id);
        $other = $this->operator($this->otherFranchise()->id);

        $this->svc()->autoCancelRefundFailed($booking);

        Notification::assertSentTo($same, AdminOpsAlertNotification::class, fn ($n, $c) => $this->mailed($n, $c));
        Notification::assertNotSentTo($other, AdminOpsAlertNotification::class);
    }

    public function test_any_failed_gateway_refund_raises_the_alert_and_the_exception_still_propagates(): void
    {
        Notification::fake();
        $booking = $this->makeBookingScenario()['booking'];
        Payment::create(['booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'gateway_order_id' => 'o_6', 'gateway_payment_id' => 'pay_fail_1', 'status' => 'captured']);
        $same = $this->operator($booking->franchise_id);
        $other = $this->operator($this->otherFranchise()->id);
        $super = $this->superAdmin();

        $inner = Mockery::mock(PaymentGateway::class);
        $inner->shouldReceive('refund')->once()->andThrow(new \RuntimeException('gateway down'));
        $gateway = new RefundAlertingGateway($inner);

        try {
            $gateway->refund('pay_fail_1', 100.0, 'test');
            $this->fail('the refund exception must propagate unchanged');
        } catch (\RuntimeException $e) {
            $this->assertSame('gateway down', $e->getMessage());
        }

        Notification::assertSentTo($same, AdminOpsAlertNotification::class, fn ($n, $c) => $this->mailed($n, $c));
        Notification::assertSentTo($super, AdminOpsAlertNotification::class, fn ($n, $c) => $this->mailed($n, $c));
        Notification::assertNotSentTo($other, AdminOpsAlertNotification::class);
    }

    public function test_a_successful_refund_raises_no_alert(): void
    {
        Notification::fake();
        $super = $this->superAdmin();
        $inner = Mockery::mock(PaymentGateway::class);
        $inner->shouldReceive('refund')->once()->andReturn(['id' => 'rfnd_ok']);

        $result = (new RefundAlertingGateway($inner))->refund('pay_ok', 10.0);

        $this->assertSame(['id' => 'rfnd_ok'], $result);
        Notification::assertNotSentTo($super, AdminOpsAlertNotification::class);
    }

    public function test_the_bound_payment_gateway_is_the_alerting_wrapper(): void
    {
        $this->assertInstanceOf(RefundAlertingGateway::class, app(PaymentGateway::class));
    }

    public function test_a_failed_mismatch_refund_alerts_through_the_gateway_wrapper(): void
    {
        Notification::fake();
        $booking = $this->makeBookingScenario()['booking'];
        $super = $this->superAdmin();
        $payment = Payment::create(['booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'gateway_order_id' => 'o_7', 'status' => 'pending']);
        $log = \App\Models\PaymentWebhookLog::create([
            'gateway' => 'razorpay', 'event' => 'payment.captured', 'gateway_order_id' => 'o_7', 'gateway_payment_id' => 'pay_mm_fail',
            'payment_id' => $payment->id, 'signature_valid' => true, 'processed' => false, 'outcome' => 'amount_mismatch', 'created_at' => now(),
            'payload' => ['payload' => ['payment' => ['entity' => ['id' => 'pay_mm_fail', 'amount' => 49999]]]],
        ]);
        $row = app(MismatchRefundService::class)->ensureForLog($log);

        $inner = Mockery::mock(PaymentGateway::class);
        $inner->shouldReceive('refund')->once()->andThrow(new \RuntimeException('boom'));
        $this->app->instance(PaymentGateway::class, new RefundAlertingGateway($inner));

        $result = app(MismatchRefundService::class)->request($row, $super, 'try it');

        $this->assertFalse($result['ok']);
        $this->assertSame(MismatchRefund::FAILED, $row->fresh()->status);
        Notification::assertSentTo($super, AdminOpsAlertNotification::class, fn ($n, $c) => $this->mailed($n, $c));
    }

    // ============================== mismatch refund escalation ==============================

    public function test_the_escalation_email_goes_only_to_holders_at_the_target_level(): void
    {
        Notification::fake();
        $booking = $this->makeBookingScenario()['booking'];
        $franchiseHolder = $this->withEmail($this->makeUserWithPermission(MismatchRefundService::PERMISSION, 'franchise', $booking->franchise_id));
        $hq = $this->withEmail($this->makeUserWithPermission(MismatchRefundService::PERMISSION, 'global'));
        $super = $this->superAdmin();
        $row = MismatchRefund::create([
            'payment_webhook_log_id' => 1, 'gateway_payment_id' => 'pay_esc', 'franchise_id' => $booking->franchise_id,
            'amount_paise' => 50000, 'status' => MismatchRefund::AWAITING_REQUEST,
        ]);

        $this->svc()->mismatchRefundEscalation($row, MismatchRefundService::LEVEL_HQ);

        Notification::assertSentTo($hq, AdminOpsAlertNotification::class, fn ($n, $c) => $this->mailed($n, $c));
        Notification::assertNotSentTo($franchiseHolder, AdminOpsAlertNotification::class);
        Notification::assertNotSentTo($super, AdminOpsAlertNotification::class);

        Notification::fake();
        $this->svc()->mismatchRefundEscalation($row, MismatchRefundService::LEVEL_SUPER);
        Notification::assertSentTo($super, AdminOpsAlertNotification::class, fn ($n, $c) => $this->mailed($n, $c));
        Notification::assertNotSentTo($hq, AdminOpsAlertNotification::class);
    }

    // ============================== the mail itself ==============================

    public function test_the_email_carries_the_alert_wording_and_a_link_to_the_admin_panel(): void
    {
        $booking = $this->makeBookingScenario()['booking'];
        $super = $this->superAdmin();
        $notification = new AdminOpsAlertNotification('dispatch_job_failure', $booking, ['mail']);

        $mail = $notification->toMail($super);

        $this->assertSame('[1CallFix Admin] Dispatch job failed', $mail->subject);
        $this->assertStringContainsString($booking->code, implode(' ', $mail->introLines));
        $this->assertSame(route('admin.bookings.show', $booking->id), $mail->actionUrl);
    }

    // ============================== Alert Emails screen ==============================

    public function test_every_type_is_on_by_default_and_unknown_types_are_off(): void
    {
        foreach (array_keys(AdminOpsAlertService::EMAIL_TYPES) as $type) {
            $this->assertTrue($this->svc()->emailEnabled($type), $type);
        }
        $this->assertFalse($this->svc()->emailEnabled('booking_created'));
        $this->assertCount(6, AdminOpsAlertService::EMAIL_TYPES); // A3 added dispute_refund_escalation
    }

    public function test_super_admin_toggles_a_type_and_it_is_audit_logged(): void
    {
        Livewire::actingAs($this->superAdmin())->test(AlertEmails::class)
            ->assertSet('enabled.refund_failed', true)
            ->set('enabled.refund_failed', false)
            ->call('save')
            ->assertSet('flashMessage', 'Alert email settings saved.');

        $this->assertFalse($this->svc()->emailEnabled('refund_failed'));
        $this->assertTrue($this->svc()->emailEnabled('cancel_payout_failed'));
        $this->assertNotNull(ActivityLog::where('description', 'Setting alerts.email.refund_failed changed')->first());
    }

    public function test_only_a_super_admin_can_open_or_save_alert_emails(): void
    {
        Livewire::actingAs($this->makeUserWithPermission('operations.manage', 'global'))->test(AlertEmails::class)->assertForbidden();
    }

    public function test_the_screen_says_plainly_when_the_mail_driver_cannot_deliver(): void
    {
        config(['mail.default' => 'log']);
        Livewire::actingAs($this->superAdmin())->test(AlertEmails::class)
            ->assertSee('not delivered');

        config(['mail.default' => 'smtp']);
        Livewire::actingAs($this->superAdmin())->test(AlertEmails::class)
            ->assertDontSee('not delivered');
    }
}
