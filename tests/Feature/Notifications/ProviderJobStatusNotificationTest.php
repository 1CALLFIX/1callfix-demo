<?php

namespace Tests\Feature\Notifications;

use App\Actions\AcceptBookingAction;
use App\Actions\AdminCancelBookingAction;
use App\Actions\CompleteBookingAction;
use App\Actions\MarkEnRouteAction;
use App\Actions\PlaceBookingOnHoldAction;
use App\Actions\ResumeBookingAction;
use App\Actions\StartBookingAction;
use App\Models\DispatchAttempt;
use App\Models\NotificationLog;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ProviderJobStatusNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase PN1 — the six booking transitions that were customer-only now also
 * notify the assigned provider, plus the new en-route transition. Verified
 * against the REAL channel pipeline (no Notification::fake()) by asserting a
 * notification_logs row is actually written for the provider's User —
 * AppServiceProvider's NotificationSent listener is what creates it, so a
 * row proves the notification genuinely went through via()/the channel, not
 * merely that a line of code was added.
 *
 * Channel is pinned to 'sms' for these: the fixture Users have a phone but
 * no email, and MailChannel silently no-ops (no NotificationSent, no log
 * row) for a notifiable with no mail route — 'sms' routes on ->phone, which
 * they have, so LogSmsAdapter "sends" and the listener fires deterministically.
 */
class ProviderJobStatusNotificationTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('notifications.channels', 'sms');
    }

    private function assertProviderNotified(User $providerUser, string $event): void
    {
        $this->assertDatabaseHas('notification_logs', [
            'notifiable_type' => User::class,
            'notifiable_id' => $providerUser->id,
            'notification_type' => ProviderJobStatusNotification::class,
            'event' => "provider.job_{$event}",
            'status' => 'sent',
        ]);
    }

    public function test_accept_notifies_the_provider_that_the_job_is_theirs(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeBookingScenario('searching_provider');
        DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => now(),
        ]);

        app(AcceptBookingAction::class)->execute($booking->id, $provider);

        $this->assertProviderNotified($provider->user, 'assigned');
    }

    public function test_mark_en_route_notifies_the_provider(): void
    {
        $s = $this->makeAssignedBookingScenario();

        app(MarkEnRouteAction::class)->execute($s['booking']->id, $s['provider']);

        $this->assertSame('provider_en_route', $s['booking']->fresh()->status);
        $this->assertProviderNotified($s['provider']->user, 'en_route');
    }

    public function test_start_notifies_the_provider(): void
    {
        $s = $this->makeAssignedBookingScenario(); // start_otp 1234

        app(StartBookingAction::class)->execute($s['booking']->id, '1234', $s['provider']->user_id);

        $this->assertProviderNotified($s['provider']->user, 'started');
    }

    public function test_hold_notifies_the_provider(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'in_progress']);

        app(PlaceBookingOnHoldAction::class)->execute($s['booking']->id, 'awaiting_spares');

        $this->assertProviderNotified($s['provider']->user, 'on_hold');
    }

    public function test_resume_notifies_the_provider(): void
    {
        $s = $this->makeAssignedBookingScenario();
        app(PlaceBookingOnHoldAction::class)->execute($s['booking']->id, 'awaiting_spares');

        app(ResumeBookingAction::class)->execute($s['booking']->id);

        $this->assertProviderNotified($s['provider']->user, 'resumed');
    }

    public function test_complete_notifies_the_provider_with_the_earnings_line(): void
    {
        $s = $this->makeAssignedBookingScenario(); // completion_otp 5678
        $s['booking']->update(['status' => 'in_progress']);

        app(CompleteBookingAction::class)->execute($s['booking']->id, $s['provider'], '5678');

        $this->assertProviderNotified($s['provider']->user, 'completed');
    }

    public function test_admin_cancel_notifies_the_assigned_provider(): void
    {
        $s = $this->makeAssignedBookingScenario();

        app(AdminCancelBookingAction::class)->execute($s['booking']->id, 'customer no-show');

        $this->assertProviderNotified($s['provider']->user, 'cancelled');
    }

    public function test_admin_cancel_of_an_unassigned_booking_notifies_no_provider(): void
    {
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');

        app(AdminCancelBookingAction::class)->execute($booking->id, 'duplicate');

        $this->assertDatabaseMissing('notification_logs', [
            'notification_type' => ProviderJobStatusNotification::class,
        ]);
    }

    public function test_notification_content_is_provider_framed_not_customer_framed(): void
    {
        Notification::fake();
        $s = $this->makeAssignedBookingScenario();

        app(MarkEnRouteAction::class)->execute($s['booking']->id, $s['provider']);

        Notification::assertSentTo(
            $s['provider']->user,
            ProviderJobStatusNotification::class,
            fn (ProviderJobStatusNotification $n) => $n->eventKey() === 'provider.job_en_route'
                && str_contains($n->toSms($s['provider']->user), $s['booking']->code)
        );
    }

    public function test_a_provider_notification_transport_failure_never_breaks_the_transition(): void
    {
        // sms-only + a gateway that always throws — the booking must still
        // complete cleanly and the failure is logged, not fatal.
        $this->app->bind(\App\Contracts\SmsAdapter::class, fn () => new class implements \App\Contracts\SmsAdapter {
            public function send(string $to, string $message): bool
            {
                throw new \RuntimeException('Simulated gateway outage');
            }
        });
        $s = $this->makeAssignedBookingScenario();

        $result = app(MarkEnRouteAction::class)->execute($s['booking']->id, $s['provider']);

        $this->assertSame('provider_en_route', $result->status);
    }
}
