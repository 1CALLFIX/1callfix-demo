<?php

namespace Tests\Feature\Journey;

use App\Actions\MarkEnRouteAction;
use App\Actions\MarkSparesAvailableAction;
use App\Actions\PlaceBookingOnHoldAction;
use App\Actions\ResumeBookingAction;
use App\Actions\StartBookingAction;
use App\Models\Setting;
use App\Notifications\BookingStatusNotification;
use App\Notifications\Channels\PushChannel;
use App\Support\Journey\JourneyBuilder;
use App\Support\Journey\JourneyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-JOURNEY-001 — the spares step, the full hold -> spares -> resume loop, and the customer
 * stage notifications (push + in-app only for the minor stages, every channel for a hold).
 */
class JourneyActionsTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function inProgress(): array
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'in_progress']);
        $s['booking'] = $s['booking']->fresh();

        return $s;
    }

    private function events($notification): string
    {
        return $notification->eventKey();
    }

    public function test_spares_available_needs_a_job_held_for_spares(): void
    {
        $s = $this->inProgress();

        // Not on hold at all.
        try {
            app(MarkSparesAvailableAction::class)->execute($s['booking']->id);
            $this->fail('expected refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not on hold waiting for spare parts', $e->getMessage());
        }

        // On hold, but for a different reason.
        app(PlaceBookingOnHoldAction::class)->execute($s['booking']->id, 'awaiting_customer_approval');
        $this->expectException(\RuntimeException::class);
        app(MarkSparesAvailableAction::class)->execute($s['booking']->id);
    }

    public function test_spares_available_is_recorded_once_per_hold(): void
    {
        Notification::fake();
        Setting::set('notifications.channels', 'mail,push,in_app');
        $s = $this->inProgress();
        app(PlaceBookingOnHoldAction::class)->execute($s['booking']->id, 'awaiting_spares');

        app(MarkSparesAvailableAction::class)->execute($s['booking']->id, $s['provider']->user_id);
        app(MarkSparesAvailableAction::class)->execute($s['booking']->id, $s['provider']->user_id);

        $this->assertSame(1, $s['booking']->statusHistory()->where('note', 'like', 'Spares available%')->count());
        $this->assertSame('on_hold', $s['booking']->fresh()->status, 'the job stays on hold until the provider resumes');
        Notification::assertSentToTimes($s['customer'], BookingStatusNotification::class, 2); // on_hold + ONE spares_available
    }

    public function test_the_whole_spares_loop_reads_back_as_a_journey(): void
    {
        Notification::fake();
        $s = $this->inProgress();
        $id = $s['booking']->id;

        app(PlaceBookingOnHoldAction::class)->execute($id, 'awaiting_spares', 'Need a compressor');
        $held = $s['booking']->fresh(['statusHistory']);
        $j = JourneyBuilder::build('service', $held->status, $held->statusHistory, JourneyContext::forBooking($held));
        $this->assertTrue($j['paused']);
        $this->assertSame('Job on hold', $j['headline']);

        app(MarkSparesAvailableAction::class)->execute($id, $s['provider']->user_id);
        app(ResumeBookingAction::class)->execute($id, 'Spares in hand');

        $after = $s['booking']->fresh(['statusHistory']);
        $this->assertSame('in_progress', $after->status);
        $this->assertNull($after->hold_reason);

        $j = JourneyBuilder::build('service', $after->status, $after->statusHistory, JourneyContext::forBooking($after));
        $episode = collect($j['steps'])->firstWhere('key', 'in_progress')['episodes'][0];
        $this->assertSame(['done', 'done', 'done'], array_column($episode['mini'], 'state'));
        $this->assertFalse($j['paused']);
    }

    public function test_customer_stage_notifications_use_push_and_in_app_only_except_a_hold(): void
    {
        Notification::fake();
        Setting::set('notifications.channels', 'mail,sms,push,in_app');
        $s = $this->makeAssignedBookingScenario();
        $id = $s['booking']->id;
        $customer = $s['customer'];

        app(MarkEnRouteAction::class)->execute($id, $s['provider'], $s['provider']->user_id);
        app(StartBookingAction::class)->execute($id, '1234', $s['provider']->user_id);
        app(PlaceBookingOnHoldAction::class)->execute($id, 'awaiting_spares');
        app(MarkSparesAvailableAction::class)->execute($id, $s['provider']->user_id);
        app(ResumeBookingAction::class)->execute($id);

        $sent = Notification::sent($customer, BookingStatusNotification::class);
        $byEvent = $sent->mapWithKeys(fn ($n) => [$n->eventKey() => $n->via($customer)]);

        $this->assertSame(['booking.en_route', 'booking.started', 'booking.on_hold', 'booking.spares_available', 'booking.resumed'], $byEvent->keys()->all());

        foreach (['booking.en_route', 'booking.started', 'booking.spares_available', 'booking.resumed'] as $minor) {
            $this->assertEqualsCanonicalizing([PushChannel::class, 'database'], $byEvent[$minor], "{$minor}: push + in-app only");
        }
        $this->assertContains('mail', $byEvent['booking.on_hold'], 'a hold reaches every configured channel');
    }

    public function test_minor_stages_send_nothing_when_only_email_is_configured(): void
    {
        Notification::fake();
        Setting::set('notifications.channels', 'mail');
        $s = $this->makeAssignedBookingScenario();

        app(MarkEnRouteAction::class)->execute($s['booking']->id, $s['provider'], $s['provider']->user_id);

        Notification::assertNothingSentTo($s['customer']);
    }

    public function test_stage_notification_copy_names_the_professional_and_the_hold_reason(): void
    {
        $s = $this->inProgress();
        $s['booking']->update(['hold_reason' => 'awaiting_spares']);
        $booking = $s['booking']->fresh(['provider.user']);

        $hold = (new BookingStatusNotification('on_hold', $booking, ['mail']))->toPush($s['customer']);
        $this->assertSame('Your job is on hold', $hold['title']);
        $this->assertStringContainsString('Waiting for spare parts', $hold['body']);
        $this->assertStringContainsString($booking->code, $hold['body']);

        $route = (new BookingStatusNotification('en_route', $booking, ['mail']))->toPush($s['customer']);
        $this->assertStringContainsString($booking->provider->user->name, $route['body']);
    }

    public function test_a_failing_customer_notification_never_breaks_the_transition(): void
    {
        Setting::set('notifications.channels', 'in_app');
        $s = $this->makeAssignedBookingScenario();
        // The in-app channel now fails on every send (its table is gone).
        \Illuminate\Support\Facades\Schema::drop('notifications');

        $booking = app(MarkEnRouteAction::class)->execute($s['booking']->id, $s['provider'], $s['provider']->user_id);

        $this->assertSame('provider_en_route', $booking->status);
        $this->assertSame('provider_en_route', $s['booking']->fresh()->status);
    }
}
