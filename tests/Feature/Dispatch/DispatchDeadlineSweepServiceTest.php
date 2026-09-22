<?php

namespace Tests\Feature\Dispatch;

use App\Actions\AcceptBookingAction;
use App\Contracts\PaymentGateway;
use App\Jobs\ServiceMatchingJob;
use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\Payment;
use App\Models\Setting;
use App\Notifications\AdminOpsAlertNotification;
use App\Notifications\BookingStatusNotification;
use App\Services\DispatchDeadlineSweepService;
use App\Services\DispatchService;
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
     * outer one commits. CancellationService::refundIfPaid()'s gateway
     * call has no try/catch of its own — before cancelOne() caught it,
     * a refund failure would propagate out of the outer transaction and
     * roll back the cancellation itself along with it, silently reverting
     * the booking to searching_provider forever. This proves the
     * cancellation now survives a failing refund.
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

        $mock = \Mockery::mock(PaymentGateway::class);
        $mock->shouldReceive('identifier')->andReturn('razorpay')->byDefault();
        $mock->shouldReceive('refund')->once()->andThrow(new \RuntimeException('gateway timeout'));
        $this->app->instance(PaymentGateway::class, $mock);

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

        $mock = \Mockery::mock(PaymentGateway::class);
        $mock->shouldReceive('identifier')->andReturn('razorpay')->byDefault();
        $mock->shouldReceive('refund')->once()->andThrow(new \RuntimeException('gateway timeout'));
        $this->app->instance(PaymentGateway::class, $mock);

        $admin = $this->opsAdminScopedTo('franchise', $franchise->id);

        app(DispatchDeadlineSweepService::class)->sweep();

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);

        Notification::assertSentTo($admin, AdminOpsAlertNotification::class, function (AdminOpsAlertNotification $n) {
            return $n->eventKey() === 'admin.ops_dispatch_refund_failed';
        });
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
}
