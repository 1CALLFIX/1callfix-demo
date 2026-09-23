<?php

namespace Tests\Feature\Dispatch;

use App\Actions\AcceptBookingAction;
use App\Contracts\PaymentGateway;
use App\Jobs\ServiceMatchingJob;
use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\WalletTransaction;
use App\Notifications\AdminOpsAlertNotification;
use App\Notifications\BookingStatusNotification;
use App\Services\DispatchDeadlineSweepService;
use App\Services\DispatchService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-IMPLEMENT-20260922-L01 — regression coverage for the Service
 * Booking dispatch-deadline T+5 escalation / T+30 auto-cancellation sweep
 * (DispatchDeadlineSweepService), plus the C-03 scoping fix and the L-02
 * queue-failure-signal minimum bundled into the same change.
 */
class DispatchDeadlineSweepServiceTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('notifications.channels', 'mail,push');
    }

    /** A `push_ops_alerts` admin scoped to $franchise via a real role_assignment — the audience AdminOpsAlertService::dispatchEscalation() actually iterates. */
    private function opsAdminScopedTo(string $scopeType, ?int $scopeId): \App\Models\User
    {
        $admin = $this->makeUserWithPermission('operations.view', $scopeType, $scopeId);
        $admin->update(['push_ops_alerts' => true, 'fcm_token' => 'admin-token-'.$admin->id]);

        return $admin;
    }

    // -----------------------------------------------------------------
    // A. Deadline set correctly
    // -----------------------------------------------------------------

    public function test_dispatch_deadline_at_is_set_only_on_the_real_pending_to_searching_provider_transition(): void
    {
        ['booking' => $booking] = $this->makeBookingScenario('pending');
        $this->assertNull($booking->dispatch_deadline_at);

        (new ServiceMatchingJob($booking->id, 1))->handle(app(DispatchService::class));

        $booking->refresh();
        $this->assertNotNull($booking->dispatch_deadline_at);
        $this->assertTrue($booking->dispatch_deadline_at->diffInSeconds(now()) < 5);
    }

    public function test_dispatch_deadline_at_is_not_backfilled_for_a_booking_that_never_went_through_pending(): void
    {
        // A booking created directly at searching_provider (bypassing the
        // real transition) never gets a deadline retroactively — the write
        // site is the transition itself, not "every time this job runs".
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');
        $this->assertNull($booking->dispatch_deadline_at);

        (new ServiceMatchingJob($booking->id, 2))->handle(app(DispatchService::class));

        $booking->refresh();
        $this->assertNull($booking->dispatch_deadline_at);
    }

    // -----------------------------------------------------------------
    // B. T+5 escalation
    // -----------------------------------------------------------------

    public function test_a_booking_past_t5_with_no_provider_triggers_exactly_one_scoped_admin_alert(): void
    {
        Notification::fake();

        ['booking' => $booking, 'franchise' => $franchise] = $this->makeBookingScenario('searching_provider');
        $booking->update(['dispatch_deadline_at' => now()->subMinutes(6)]);

        $admin = $this->opsAdminScopedTo('franchise', $franchise->id);

        app(DispatchDeadlineSweepService::class)->sweep();

        Notification::assertSentTo($admin, AdminOpsAlertNotification::class, function (AdminOpsAlertNotification $n) {
            return $n->eventKey() === 'admin.ops_dispatch_escalation';
        });
        Notification::assertSentToTimes($admin, AdminOpsAlertNotification::class, 1);

        $booking->refresh();
        $this->assertNotNull($booking->dispatch_escalated_at);
    }

    public function test_admins_outside_the_bookings_franchise_do_not_receive_the_escalation_alert(): void
    {
        Notification::fake();

        ['booking' => $booking, 'franchise' => $franchiseInside] = $this->makeBookingScenario('searching_provider');
        $booking->update(['dispatch_deadline_at' => now()->subMinutes(6)]);

        [, , $franchiseOutside] = $this->makeFranchiseTree();

        $insideAdmin = $this->opsAdminScopedTo('franchise', $franchiseInside->id);
        $outsideAdmin = $this->opsAdminScopedTo('franchise', $franchiseOutside->id);
        $noGrantAdmin = $this->makeUserWithNoPermissions();
        $noGrantAdmin->update(['push_ops_alerts' => true, 'fcm_token' => 'admin-token-nogrant']);

        app(DispatchDeadlineSweepService::class)->sweep();

        Notification::assertSentTo($insideAdmin, AdminOpsAlertNotification::class);
        Notification::assertNotSentTo($outsideAdmin, AdminOpsAlertNotification::class);
        Notification::assertNotSentTo($noGrantAdmin, AdminOpsAlertNotification::class);
    }

    public function test_a_super_admin_always_receives_the_escalation_alert_regardless_of_scope(): void
    {
        Notification::fake();

        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');
        $booking->update(['dispatch_deadline_at' => now()->subMinutes(6)]);

        $superAdmin = $this->makeSuperAdmin();
        $superAdmin->update(['push_ops_alerts' => true, 'fcm_token' => 'super-admin-token']);

        app(DispatchDeadlineSweepService::class)->sweep();

        Notification::assertSentTo($superAdmin, AdminOpsAlertNotification::class);
    }

    public function test_a_booking_assigned_before_t5_does_not_trigger_the_escalation_alert(): void
    {
        Notification::fake();

        ['booking' => $booking, 'franchise' => $franchise, 'provider' => $provider] = $this->makeBookingScenario('assigned');
        $booking->update(['provider_id' => $provider->id, 'dispatch_deadline_at' => now()->subMinutes(6)]);

        $admin = $this->opsAdminScopedTo('franchise', $franchise->id);

        app(DispatchDeadlineSweepService::class)->sweep();

        Notification::assertNotSentTo($admin, AdminOpsAlertNotification::class);
        $this->assertNull($booking->fresh()->dispatch_escalated_at);
    }

    public function test_a_booking_not_yet_past_t5_does_not_trigger_the_escalation_alert(): void
    {
        Notification::fake();

        ['booking' => $booking, 'franchise' => $franchise] = $this->makeBookingScenario('searching_provider');
        $booking->update(['dispatch_deadline_at' => now()->subMinutes(2)]);

        $admin = $this->opsAdminScopedTo('franchise', $franchise->id);

        app(DispatchDeadlineSweepService::class)->sweep();

        Notification::assertNotSentTo($admin, AdminOpsAlertNotification::class);
    }

    // -----------------------------------------------------------------
    // C. T+30 auto-cancellation
    // -----------------------------------------------------------------

    public function test_a_booking_past_t30_with_no_provider_is_auto_cancelled_fee_free_and_notifies_the_customer(): void
    {
        Notification::fake();

        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');
        $booking->update(['dispatch_deadline_at' => now()->subMinutes(31)]);

        app(DispatchDeadlineSweepService::class)->sweep();

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
        $this->assertSame(0.0, (float) $booking->cancellation_fee);
        $this->assertStringContainsString('Platform dispatch failure', $booking->cancellation_note);

        Notification::assertSentTo($booking->customer, BookingStatusNotification::class, function (BookingStatusNotification $n) {
            return $n->eventKey() === 'booking.no_provider_found';
        });
        Notification::assertNotSentTo($booking->customer, BookingStatusNotification::class, function (BookingStatusNotification $n) {
            return $n->eventKey() === 'booking.cancelled';
        });
    }

    /**
     * REF 1CF-IMPLEMENT-20260922-L01 (code-review finding, closed same
     * session) — cancelOne() runs AdminCancelBookingAction::execute()
     * NESTED inside its own outer transaction, so execute()'s own inner
     * transaction only opens a savepoint; it isn't durable until the
     * outer one commits. CancellationService::refundIfPaid()'s refund
     * call has no try/catch of its own — before cancelOne() caught it,
     * a refund failure would propagate out of the outer transaction and
     * roll back the cancellation itself along with it, silently reverting
     * the booking to searching_provider forever. This proves the
     * cancellation now survives a failing refund.
     *
     * REF 1CF-IMPLEMENT-20260923-MAIN-WALLET — updated to mock
     * WalletService::credit() rather than PaymentGateway::refund(): since
     * the Main Wallet business decision, a razorpay-paid L-01
     * auto-cancellation goes through the wallet-credit branch, not the
     * gateway-refund branch, so a PaymentGateway mock would never even be
     * invoked here and this test would silently stop testing anything.
     */
    public function test_a_failing_refund_does_not_roll_back_the_auto_cancellation(): void
    {
        ['booking' => $booking, 'customer' => $customer] = $this->makeBookingScenario('searching_provider');
        $booking->update(['dispatch_deadline_at' => now()->subMinutes(31)]);

        Payment::create([
            'booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => 500,
            'gateway' => 'razorpay', 'gateway_payment_id' => 'pay_will_fail_refund',
            'status' => 'captured', 'captured_at' => now(),
        ]);

        $mock = \Mockery::mock(WalletService::class);
        $mock->shouldReceive('credit')->once()->andThrow(new \RuntimeException('wallet ledger write failed'));
        $this->app->instance(WalletService::class, $mock);

        app(DispatchDeadlineSweepService::class)->sweep();

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status, 'the cancellation must survive even though the refund failed');
        $this->assertStringContainsString('Platform dispatch failure', $booking->cancellation_note);
    }

    /**
     * REF 1CF-IMPLEMENT-20260922-L01 — payment-failure visibility as a
     * release safeguard: the cancellation surviving a failing refund
     * (previous test) isn't enough on its own if nobody is ever told
     * about the failed refund. This is the distinct admin alert closing
     * that gap — separate from dispatchEscalation()'s dispatch-health
     * alerts, and firing regardless of whether the booking was also a
     * job-failure case, because this alert is about the refund, not the
     * dispatch outcome.
     *
     * REF 1CF-IMPLEMENT-20260923-MAIN-WALLET — same update as the test
     * above: mocks WalletService::credit(), the branch this path actually
     * takes now.
     */
    public function test_a_failing_refund_fires_a_distinct_scoped_admin_alert(): void
    {
        Notification::fake();

        ['booking' => $booking, 'franchise' => $franchise] = $this->makeBookingScenario('searching_provider');
        $booking->update(['dispatch_deadline_at' => now()->subMinutes(31)]);

        Payment::create([
            'booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => 500,
            'gateway' => 'razorpay', 'gateway_payment_id' => 'pay_will_fail_refund_2',
            'status' => 'captured', 'captured_at' => now(),
        ]);

        $mock = \Mockery::mock(WalletService::class);
        $mock->shouldReceive('credit')->once()->andThrow(new \RuntimeException('wallet ledger write failed'));
        $this->app->instance(WalletService::class, $mock);

        $admin = $this->opsAdminScopedTo('franchise', $franchise->id);

        app(DispatchDeadlineSweepService::class)->sweep();

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);

        Notification::assertSentTo($admin, AdminOpsAlertNotification::class, function (AdminOpsAlertNotification $n) {
            return $n->eventKey() === 'admin.ops_dispatch_refund_failed';
        });
    }

    /**
     * REF 1CF-IMPLEMENT-20260923-MAIN-WALLET — the finalized business
     * decision under test: a T+30 no-provider-found auto-cancellation on a
     * booking that was paid via a REAL Razorpay capture (not wallet)
     * credits the customer's Main Wallet instead of issuing a gateway
     * refund back to the card/bank. Asserts the full chain: no gateway
     * call, exactly one wallet credit, correct amount/ref/source, Payment
     * and booking payment_status updated, customer notified.
     */
    public function test_a_razorpay_paid_no_provider_cancellation_credits_the_main_wallet_exactly_once(): void
    {
        Notification::fake();

        ['booking' => $booking, 'customer' => $customer] = $this->makeBookingScenario('searching_provider');
        $booking->update(['dispatch_deadline_at' => now()->subMinutes(31)]);

        $payment = Payment::create([
            'booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => 500,
            'gateway' => 'razorpay', 'gateway_payment_id' => 'pay_mainwallet_1',
            'status' => 'captured', 'captured_at' => now(),
        ]);

        $mock = \Mockery::mock(PaymentGateway::class);
        $mock->shouldReceive('identifier')->andReturn('razorpay')->byDefault();
        $mock->shouldNotReceive('refund'); // the whole point: no real gateway refund for this path
        $this->app->instance(PaymentGateway::class, $mock);

        $openingBalance = app(WalletService::class)->balance($customer);

        app(DispatchDeadlineSweepService::class)->sweep();

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
        $this->assertSame(0.0, (float) $booking->cancellation_fee);
        $this->assertSame('refunded', $booking->payment_status);

        $payment->refresh();
        $this->assertSame('refunded', $payment->status);
        $this->assertEqualsWithDelta(500.0, (float) $payment->refunded_amount, 0.001);

        $ref = "booking:{$booking->id}:wallet-refund";
        $credits = WalletTransaction::whereHas('wallet', fn ($q) => $q->where('user_id', $customer->id))
            ->where('ref', $ref)->get();
        $this->assertCount(1, $credits, 'exactly one wallet credit for this booking\'s refund');
        $this->assertTrue($credits->first()->is_credit);
        $this->assertEqualsWithDelta(500.0, (float) $credits->first()->amount, 0.001);
        $this->assertStringContainsString('no provider found', strtolower($credits->first()->reason));

        $this->assertEqualsWithDelta($openingBalance + 500.0, app(WalletService::class)->balance($customer), 0.001);

        Notification::assertSentTo($customer, \App\Notifications\PaymentStatusNotification::class);
    }

    /**
     * REF 1CF-IMPLEMENT-20260923-MAIN-WALLET — repeated-sweep idempotency
     * for the wallet-credit path specifically (the general
     * "sweep-twice-doesn't-double-cancel" case is already covered above
     * without a Payment fixture; this proves the money side is equally
     * safe, not just the status side).
     */
    public function test_repeated_sweep_does_not_duplicate_the_main_wallet_refund_credit(): void
    {
        ['booking' => $booking, 'customer' => $customer] = $this->makeBookingScenario('searching_provider');
        $booking->update(['dispatch_deadline_at' => now()->subMinutes(31)]);

        Payment::create([
            'booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => 500,
            'gateway' => 'razorpay', 'gateway_payment_id' => 'pay_mainwallet_2',
            'status' => 'captured', 'captured_at' => now(),
        ]);

        $service = app(DispatchDeadlineSweepService::class);
        $service->sweep();
        $service->sweep();

        $ref = "booking:{$booking->id}:wallet-refund";
        $this->assertSame(1, WalletTransaction::where('ref', $ref)->count());
        $this->assertEqualsWithDelta(500.0, app(WalletService::class)->balance($customer), 0.001);
    }

    /**
     * REF 1CF-IMPLEMENT-20260923-MAIN-WALLET — the underlying DB-level
     * guarantee every duplicate-credit safeguard above ultimately relies
     * on: wallet_transactions.ref is UNIQUE (pre-existing schema, not
     * added by this task). True multi-process concurrency isn't
     * simulated in this suite (same documented limitation as this file's
     * other concurrency tests); this proves the actual mechanism a
     * concurrent duplicate attempt would hit, directly.
     */
    public function test_a_duplicate_wallet_refund_ref_is_rejected_by_the_database_constraint(): void
    {
        ['booking' => $booking, 'customer' => $customer] = $this->makeBookingScenario('searching_provider');
        $wallet = app(WalletService::class);
        $ref = "booking:{$booking->id}:wallet-refund";

        $wallet->credit($customer, 500.0, reason: 'first refund', ref: $ref);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $wallet->credit($customer, 500.0, reason: 'duplicate refund attempt', ref: $ref);
    }

    public function test_a_booking_assigned_before_t30_is_never_touched_by_the_sweep(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeBookingScenario('assigned');
        $booking->update(['provider_id' => $provider->id, 'dispatch_deadline_at' => now()->subMinutes(31)]);

        app(DispatchDeadlineSweepService::class)->sweep();

        $booking->refresh();
        $this->assertSame('assigned', $booking->status);
        $this->assertNull($booking->cancellation_note);
    }

    public function test_a_booking_not_yet_past_t30_is_not_cancelled(): void
    {
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');
        $booking->update(['dispatch_deadline_at' => now()->subMinutes(10)]);

        app(DispatchDeadlineSweepService::class)->sweep();

        $booking->refresh();
        $this->assertSame('searching_provider', $booking->status);
    }

    // -----------------------------------------------------------------
    // D. Idempotency and concurrency
    // -----------------------------------------------------------------

    public function test_running_the_sweep_twice_in_immediate_succession_does_not_double_cancel_or_double_alert(): void
    {
        Notification::fake();

        ['booking' => $booking, 'franchise' => $franchise] = $this->makeBookingScenario('searching_provider');
        $booking->update(['dispatch_deadline_at' => now()->subMinutes(31)]);

        $admin = $this->opsAdminScopedTo('franchise', $franchise->id);

        $service = app(DispatchDeadlineSweepService::class);
        $service->sweep();
        $service->sweep();

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);

        Notification::assertSentToTimes($admin, AdminOpsAlertNotification::class, 1);
        Notification::assertSentToTimes($booking->customer, BookingStatusNotification::class, 1);
    }

    public function test_a_booking_accepted_by_a_provider_concurrently_with_the_sweep_is_not_cancelled(): void
    {
        ['booking' => $booking, 'provider' => $provider, 'service' => $service] = $this->makeBookingScenario('searching_provider');
        $this->makeEligible($provider, $service->category_id);
        $booking->update(['dispatch_deadline_at' => now()->subMinutes(31)]);

        DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => now(),
        ]);

        // Deterministic ordering (documented limitation on true concurrency,
        // same approach the C-01 tests already use): the acceptance
        // commits fully BEFORE the sweep ever acquires the row lock.
        $accepted = app(AcceptBookingAction::class)->execute($booking->id, $provider);
        $this->assertSame('assigned', $accepted->status);

        app(DispatchDeadlineSweepService::class)->sweep();

        $booking->refresh();
        $this->assertSame('assigned', $booking->status);
        $this->assertSame($provider->id, $booking->provider_id);
    }

    /**
     * REF 1CF-IMPLEMENT-20260922-L01 — the acceptance race, the other
     * ordering: the sweep wins the row lock first and auto-cancels at
     * T+30, and ONLY THEN does a provider attempt to accept the same
     * offer. Before AcceptBookingAction re-checked booking status (not
     * just provider_id-null), this would have wrongly succeeded — a
     * cancelled-with-no-provider booking still has provider_id === null,
     * so the old check saw nothing wrong with assigning it after the
     * fact. Deterministic ordering here for the same documented reason as
     * the sibling test above (true concurrency isn't simulated); this
     * covers the ordering that test does not.
     */
    public function test_a_provider_cannot_accept_a_booking_the_sweep_already_auto_cancelled(): void
    {
        ['booking' => $booking, 'provider' => $provider, 'service' => $service] = $this->makeBookingScenario('searching_provider');
        $this->makeEligible($provider, $service->category_id);
        $booking->update(['dispatch_deadline_at' => now()->subMinutes(31)]);

        DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => now(),
        ]);

        app(DispatchDeadlineSweepService::class)->sweep();
        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no longer available');

        try {
            app(AcceptBookingAction::class)->execute($booking->id, $provider);
        } finally {
            $booking->refresh();
            $this->assertSame('cancelled', $booking->status);
            $this->assertNull($booking->provider_id);
        }
    }

    // -----------------------------------------------------------------
    // E. Queue-failure signal (L-02 minimum)
    // -----------------------------------------------------------------

    public function test_a_queue_failure_produces_a_distinct_admin_alert_from_a_genuine_no_provider_escalation(): void
    {
        Notification::fake();

        ['booking' => $booking, 'franchise' => $franchise] = $this->makeBookingScenario('searching_provider');
        $booking->update(['dispatch_deadline_at' => now()->subMinutes(6)]);

        $admin = $this->opsAdminScopedTo('franchise', $franchise->id);

        (new ServiceMatchingJob($booking->id, 2))->failed(new \RuntimeException('queue worker died mid-round'));

        app(DispatchDeadlineSweepService::class)->sweep();

        Notification::assertSentTo($admin, AdminOpsAlertNotification::class, function (AdminOpsAlertNotification $n) {
            return $n->eventKey() === 'admin.ops_dispatch_job_failure';
        });
        Notification::assertNotSentTo($admin, AdminOpsAlertNotification::class, function (AdminOpsAlertNotification $n) {
            return $n->eventKey() === 'admin.ops_dispatch_escalation';
        });
    }

    public function test_a_queue_failure_produces_a_distinct_cancellation_reason_at_t30(): void
    {
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');
        $booking->update(['dispatch_deadline_at' => now()->subMinutes(31)]);

        (new ServiceMatchingJob($booking->id, 2))->failed(new \RuntimeException('queue worker died mid-round'));

        app(DispatchDeadlineSweepService::class)->sweep();

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
        $this->assertStringContainsString('dispatch error', strtolower($booking->cancellation_note));
        $this->assertStringNotContainsString('no provider could be found', strtolower($booking->cancellation_note));
    }

    // -----------------------------------------------------------------
    // F. Regression — ServiceMatchingJob's normal round behavior is unchanged
    // -----------------------------------------------------------------

    public function test_normal_dispatch_rounds_after_the_transition_do_not_touch_dispatch_deadline_at_again(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeBookingScenario('pending');
        $provider->update(['skills' => [$booking->service->category_id], 'current_lat' => 1.0, 'current_lng' => 1.0, 'location_updated_at' => now()]);

        (new ServiceMatchingJob($booking->id, 1))->handle(app(DispatchService::class));
        $firstDeadline = $booking->fresh()->dispatch_deadline_at;

        DispatchAttempt::where('booking_id', $booking->id)->update(['notified_at' => now()->subSeconds(60)]);
        (new ServiceMatchingJob($booking->id, 2))->handle(app(DispatchService::class));

        $this->assertTrue($booking->fresh()->dispatch_deadline_at->equalTo($firstDeadline));
    }

    // -----------------------------------------------------------------
    // F. Scheduled bookings are excluded (REF 1CF-IMPLEMENT-20260923-F01)
    // -----------------------------------------------------------------

    /**
     * The reproduced F-01 scenario, through the real dispatch transition:
     * a booking scheduled days ahead still enters searching_provider at
     * creation, but neither T+5 nor T+30 may act on it.
     */
    public function test_a_booking_scheduled_days_ahead_is_neither_escalated_nor_cancelled_past_t30(): void
    {
        Notification::fake();
        Setting::set('dispatch.max_rounds', '1');

        ['booking' => $booking, 'franchise' => $franchise] = $this->makeBookingScenario('pending');
        $booking->update(['scheduled_at' => now()->addDays(3)]);
        $admin = $this->opsAdminScopedTo('franchise', $franchise->id);

        (new ServiceMatchingJob($booking->id))->handle(app(DispatchService::class));
        $this->assertSame('searching_provider', $booking->fresh()->status);
        $this->assertNotNull($booking->fresh()->dispatch_deadline_at);

        $this->travel(31)->minutes();
        $result = app(DispatchDeadlineSweepService::class)->sweep();

        $this->assertSame(['escalated' => 0, 'cancelled' => 0], $result);

        $booking->refresh();
        $this->assertSame('searching_provider', $booking->status);
        $this->assertNull($booking->dispatch_escalated_at);
        $this->assertNull($booking->cancellation_note);

        Notification::assertNotSentTo($admin, AdminOpsAlertNotification::class);
        Notification::assertNotSentTo($booking->customer, BookingStatusNotification::class, function (BookingStatusNotification $n) {
            return in_array($n->eventKey(), ['booking.no_provider_found', 'booking.cancelled'], true);
        });
    }

    /** Any non-null scheduled_at is excluded — a slot two hours out the same as one at the 14-day limit. */
    public function test_near_and_far_future_scheduled_bookings_are_both_excluded(): void
    {
        Notification::fake();

        $near = $this->makeBookingScenario('searching_provider')['booking'];
        $near->update(['scheduled_at' => now()->addHours(2), 'dispatch_deadline_at' => now()->subMinutes(31)]);

        $far = $this->makeBookingScenario('searching_provider')['booking'];
        $far->update(['scheduled_at' => now()->addDays(14), 'dispatch_deadline_at' => now()->subMinutes(31)]);

        $this->assertSame(['escalated' => 0, 'cancelled' => 0], app(DispatchDeadlineSweepService::class)->sweep());

        foreach ([$near, $far] as $booking) {
            $booking->refresh();
            $this->assertSame('searching_provider', $booking->status);
            $this->assertNull($booking->dispatch_escalated_at);
        }
    }

    /** Regression: in the same sweep, an instant booking with identical elapsed time is still escalated and cancelled. */
    public function test_an_instant_booking_is_still_escalated_and_cancelled_alongside_an_excluded_scheduled_one(): void
    {
        Notification::fake();

        ['booking' => $instant, 'franchise' => $franchise] = $this->makeBookingScenario('searching_provider');
        $instant->update(['dispatch_deadline_at' => now()->subMinutes(31)]);
        $admin = $this->opsAdminScopedTo('franchise', $franchise->id);

        $scheduled = $this->makeBookingScenario('searching_provider')['booking'];
        $scheduled->update(['scheduled_at' => now()->addDays(3), 'dispatch_deadline_at' => now()->subMinutes(31)]);

        $this->assertSame(['escalated' => 1, 'cancelled' => 1], app(DispatchDeadlineSweepService::class)->sweep());

        $instant->refresh();
        $this->assertSame('cancelled', $instant->status);
        $this->assertNotNull($instant->dispatch_escalated_at);
        Notification::assertSentTo($admin, AdminOpsAlertNotification::class, function (AdminOpsAlertNotification $n) {
            return $n->eventKey() === 'admin.ops_dispatch_escalation';
        });
        Notification::assertSentTo($instant->customer, BookingStatusNotification::class, function (BookingStatusNotification $n) {
            return $n->eventKey() === 'booking.no_provider_found';
        });

        $scheduled->refresh();
        $this->assertSame('searching_provider', $scheduled->status);
        $this->assertNull($scheduled->dispatch_escalated_at);
    }
}
