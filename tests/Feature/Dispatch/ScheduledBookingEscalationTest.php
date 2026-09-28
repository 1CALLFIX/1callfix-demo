<?php

namespace Tests\Feature\Dispatch;

use App\Contracts\PaymentGateway;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\WalletTransaction;
use App\Notifications\AdminOpsAlertNotification;
use App\Notifications\BookingStatusNotification;
use App\Services\ScheduledBookingEscalationService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-SCHEDULING-DISPATCH-001 — ScheduledBookingEscalationService.
 * Tests 25-33 of the brief's own test plan.
 */
class ScheduledBookingEscalationTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('notifications.channels', 'mail,push');
        Setting::set('dispatch.scheduled_early_warning_hours', '3');
        Setting::set('booking.scheduling_buffer_minutes', '30');
    }

    private function opsAdminScopedTo(string $scopeType, ?int $scopeId): \App\Models\User
    {
        $admin = $this->makeUserWithPermission('operations.view', $scopeType, $scopeId);
        $admin->update(['push_ops_alerts' => true, 'fcm_token' => 'admin-token-'.$admin->id]);

        return $admin;
    }

    private function openScheduledBooking(\Carbon\CarbonInterface $scheduledAt): array
    {
        $scenario = $this->makeBookingScenario('searching_provider');
        $scenario['booking']->update([
            'scheduled_at' => $scheduledAt,
            'payment_status' => 'paid',
            'scheduled_offers_sent_at' => now(),
            'scheduled_last_offer_at' => now(),
        ]);
        $scenario['booking'] = $scenario['booking']->fresh();

        return $scenario;
    }

    // -----------------------------------------------------------------
    // 25/26. Early warning
    // -----------------------------------------------------------------

    public function test_early_warning_admin_alert_fires_at_the_configured_time(): void
    {
        Notification::fake();

        ['booking' => $booking, 'franchise' => $franchise] = $this->openScheduledBooking(now()->addHours(2));
        $admin = $this->opsAdminScopedTo('franchise', $franchise->id);

        app(ScheduledBookingEscalationService::class)->sweep();

        Notification::assertSentTo($admin, AdminOpsAlertNotification::class, fn ($n) => $n->eventKey() === 'admin.ops_scheduled_early_warning');
        $this->assertNotNull($booking->fresh()->scheduled_early_warning_at);
    }

    public function test_customer_still_finding_your_professional_notification_fires(): void
    {
        Notification::fake();

        ['booking' => $booking] = $this->openScheduledBooking(now()->addHours(2));

        app(ScheduledBookingEscalationService::class)->sweep();

        Notification::assertSentTo($booking->customer, BookingStatusNotification::class, fn ($n) => $n->eventKey() === 'booking.scheduled_still_searching');
    }

    public function test_early_warning_does_not_fire_before_its_threshold(): void
    {
        Notification::fake();

        ['booking' => $booking] = $this->openScheduledBooking(now()->addHours(5));

        app(ScheduledBookingEscalationService::class)->sweep();

        Notification::assertNothingSent();
        $this->assertNull($booking->fresh()->scheduled_early_warning_at);
    }

    // -----------------------------------------------------------------
    // 27. Urgent alert at scheduled_at - buffer
    // -----------------------------------------------------------------

    public function test_urgent_admin_alert_fires_at_scheduled_at_minus_buffer(): void
    {
        Notification::fake();

        ['booking' => $booking, 'franchise' => $franchise] = $this->openScheduledBooking(now()->addMinutes(20));
        $admin = $this->opsAdminScopedTo('franchise', $franchise->id);

        app(ScheduledBookingEscalationService::class)->sweep();

        Notification::assertSentTo($admin, AdminOpsAlertNotification::class, fn ($n) => $n->eventKey() === 'admin.ops_scheduled_urgent_alert');
        $this->assertNotNull($booking->fresh()->scheduled_urgent_alert_at);
    }

    // -----------------------------------------------------------------
    // 28. Late-created booking: passed milestones fire once, never twice
    // -----------------------------------------------------------------

    public function test_a_late_created_booking_fires_the_passed_milestone_exactly_once(): void
    {
        Notification::fake();

        // scheduled_at is only 10 minutes out — BOTH the early-warning
        // (3h) and urgent (30min buffer) thresholds are already past at
        // creation time.
        ['booking' => $booking, 'franchise' => $franchise] = $this->openScheduledBooking(now()->addMinutes(10));
        $admin = $this->opsAdminScopedTo('franchise', $franchise->id);

        app(ScheduledBookingEscalationService::class)->sweep();
        Notification::assertSentToTimes($admin, AdminOpsAlertNotification::class, 2); // early-warning + urgent, once each

        app(ScheduledBookingEscalationService::class)->sweep();
        Notification::assertSentToTimes($admin, AdminOpsAlertNotification::class, 2); // unchanged — no double-fire
    }

    // -----------------------------------------------------------------
    // 29/30/31/32. Auto-cancel only at scheduled_at, zero fee, refund routing
    // -----------------------------------------------------------------

    public function test_scheduled_booking_auto_cancels_only_at_scheduled_at_when_enabled(): void
    {
        Setting::set('dispatch.scheduled_auto_cancel_enabled', '1');

        ['booking' => $notYetDue] = $this->openScheduledBooking(now()->addMinutes(5));
        ['booking' => $due] = $this->openScheduledBooking(now()->subMinutes(1));

        $result = app(ScheduledBookingEscalationService::class)->sweep();

        $this->assertSame('searching_provider', $notYetDue->fresh()->status);
        $this->assertSame('cancelled', $due->fresh()->status);
        $this->assertSame(1, $result['auto_cancelled']);
    }

    public function test_auto_cancel_uses_existing_refund_routing_and_zero_fee(): void
    {
        Setting::set('dispatch.scheduled_auto_cancel_enabled', '1');
        Setting::set('payment.wallet_enabled', '1');

        ['booking' => $booking, 'customer' => $customer] = $this->openScheduledBooking(now()->subMinutes(1));
        $payment = Payment::create([
            'booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => 500,
            'gateway' => 'razorpay', 'gateway_payment_id' => 'pay_scheduled_1',
            'status' => 'captured', 'captured_at' => now(),
        ]);
        $booking->update(['payment_status' => 'paid', 'price_quoted' => 500]);

        $mock = \Mockery::mock(PaymentGateway::class);
        $mock->shouldReceive('identifier')->andReturn('razorpay')->byDefault();
        $mock->shouldNotReceive('refund'); // Main-Wallet routing, not a real gateway refund
        $this->app->instance(PaymentGateway::class, $mock);

        app(ScheduledBookingEscalationService::class)->sweep();

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
        $this->assertSame(0.0, (float) $booking->cancellation_fee);
        $this->assertSame('refunded', $payment->fresh()->status);

        // REF 1CF-IMPLEMENT-20260923-MAIN-WALLET routing — credited to the
        // customer's Main Wallet, same as the ASAP T+30 sweep.
        $credits = WalletTransaction::whereHas('wallet', fn ($q) => $q->where('user_id', $customer->id))
            ->where('ref', "booking:{$booking->id}:wallet-refund")
            ->get();
        $this->assertCount(1, $credits);
        $this->assertTrue($credits->first()->is_credit);
        $this->assertEqualsWithDelta(500.0, (float) $credits->first()->amount, 0.001);
    }

    public function test_no_cancellation_happens_before_scheduled_at(): void
    {
        Setting::set('dispatch.scheduled_auto_cancel_enabled', '1');

        ['booking' => $booking] = $this->openScheduledBooking(now()->addMinutes(2));

        app(ScheduledBookingEscalationService::class)->sweep();

        $this->assertSame('searching_provider', $booking->fresh()->status);
    }

    public function test_admin_auto_cancel_off_prevents_automatic_cancellation(): void
    {
        Setting::set('dispatch.scheduled_auto_cancel_enabled', '0');

        ['booking' => $booking] = $this->openScheduledBooking(now()->subMinutes(5));

        $result = app(ScheduledBookingEscalationService::class)->sweep();

        $this->assertSame(0, $result['auto_cancelled']);
        $this->assertSame('searching_provider', $booking->fresh()->status);
    }

    public function test_an_already_assigned_scheduled_booking_is_never_auto_cancelled(): void
    {
        Setting::set('dispatch.scheduled_auto_cancel_enabled', '1');

        ['booking' => $booking, 'provider' => $provider] = $this->openScheduledBooking(now()->subMinutes(1));
        $booking->update(['provider_id' => $provider->id, 'status' => 'assigned']);

        app(ScheduledBookingEscalationService::class)->sweep();

        $this->assertSame('assigned', $booking->fresh()->status);
    }
}
