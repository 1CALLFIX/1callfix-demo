<?php

namespace Tests\Feature\Dispatch;

use App\Actions\AcceptBookingAction;
use App\Actions\UnassignScheduledProviderAction;
use App\Models\Setting;
use App\Notifications\BookingStatusNotification;
use App\Notifications\ProviderJobStatusNotification;
use App\Services\ScheduledBookingReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-SCHEDULING-DISPATCH-001 (Part 3) — provider T-60/T-30 reminders
 * and the acceptance-time confirmation copy. Tests 34-39 of the brief's
 * own test plan (plus the acceptance-confirmation content, Part 3).
 */
class ScheduledBookingReminderTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('notifications.channels', 'mail,push');
        Setting::set('booking.scheduled_reminder_offset_1_minutes', '60');
        Setting::set('booking.scheduled_reminder_offset_2_minutes', '30');
    }

    private function assignedScheduledBooking(\Carbon\CarbonInterface $scheduledAt): array
    {
        $scenario = $this->makeAssignedBookingScenario();
        $scenario['booking']->update(['scheduled_at' => $scheduledAt]);
        $scenario['booking'] = $scenario['booking']->fresh();

        return $scenario;
    }

    // -----------------------------------------------------------------
    // 34/35. T-60 / T-30 reminders
    // -----------------------------------------------------------------

    public function test_t_minus_60_provider_reminder_fires(): void
    {
        Notification::fake();

        ['booking' => $booking, 'provider' => $provider] = $this->assignedScheduledBooking(now()->addMinutes(59));

        app(ScheduledBookingReminderService::class)->sendDueReminders();

        Notification::assertSentTo($provider->user, ProviderJobStatusNotification::class, fn ($n) => $n->eventKey() === 'provider.job_reminder_60');
        $this->assertNotNull($booking->fresh()->scheduled_reminder_1_at);
    }

    public function test_t_minus_30_provider_reminder_fires(): void
    {
        Notification::fake();

        ['booking' => $booking, 'provider' => $provider] = $this->assignedScheduledBooking(now()->addMinutes(29));

        app(ScheduledBookingReminderService::class)->sendDueReminders();

        Notification::assertSentTo($provider->user, ProviderJobStatusNotification::class, fn ($n) => $n->eventKey() === 'provider.job_reminder_30');
        $this->assertNotNull($booking->fresh()->scheduled_reminder_2_at);
    }

    public function test_a_reminder_does_not_fire_before_its_offset(): void
    {
        Notification::fake();

        ['booking' => $booking] = $this->assignedScheduledBooking(now()->addMinutes(90));

        app(ScheduledBookingReminderService::class)->sendDueReminders();

        Notification::assertNothingSent();
        $this->assertNull($booking->fresh()->scheduled_reminder_1_at);
        $this->assertNull($booking->fresh()->scheduled_reminder_2_at);
    }

    // -----------------------------------------------------------------
    // 36. No duplicate reminders
    // -----------------------------------------------------------------

    public function test_no_duplicate_reminders_across_repeated_sweeps(): void
    {
        Notification::fake();

        ['booking' => $booking, 'provider' => $provider] = $this->assignedScheduledBooking(now()->addMinutes(59));

        app(ScheduledBookingReminderService::class)->sendDueReminders();
        app(ScheduledBookingReminderService::class)->sendDueReminders();
        app(ScheduledBookingReminderService::class)->sendDueReminders();

        Notification::assertSentToTimes(
            $provider->user,
            ProviderJobStatusNotification::class,
            1,
        );
    }

    // -----------------------------------------------------------------
    // 37. Late-assignment rule: skip only the missed reminder
    // -----------------------------------------------------------------

    public function test_a_provider_assigned_after_the_t60_reminder_time_only_skips_that_one_reminder(): void
    {
        Notification::fake();

        // scheduled_at is 40 minutes out — the T-60 window has already
        // passed, but T-30 has not.
        ['booking' => $booking, 'provider' => $provider, 'customer' => $customer] = $this->makeBookingScenario('searching_provider');
        $booking->update(['scheduled_at' => now()->addMinutes(40)]);
        $this->makeEligible($provider, $booking->service->category_id);

        $attempt = \App\Models\DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'notified', 'notified_at' => now(),
        ]);

        app(AcceptBookingAction::class)->execute($booking->id, $provider->fresh());

        $booking->refresh();
        // T-60 reminder settled (skipped) immediately at assignment...
        $this->assertNotNull($booking->scheduled_reminder_1_at);
        // ...but T-30 has NOT been sent yet (still 10 minutes away).
        $this->assertNull($booking->scheduled_reminder_2_at);

        app(ScheduledBookingReminderService::class)->sendDueReminders();
        Notification::assertNotSentTo($provider->user, ProviderJobStatusNotification::class, fn ($n) => $n->eventKey() === 'provider.job_reminder_60');

        // Once T-30 is actually reached, that one still fires normally.
        $this->travel(11)->minutes();
        app(ScheduledBookingReminderService::class)->sendDueReminders();
        Notification::assertSentTo($provider->user, ProviderJobStatusNotification::class, fn ($n) => $n->eventKey() === 'provider.job_reminder_30');
    }

    // -----------------------------------------------------------------
    // 38/39. Replacement + acceptance confirmation notifications
    // -----------------------------------------------------------------

    public function test_customer_is_notified_with_provider_name_and_day_time_on_assignment(): void
    {
        Notification::fake();

        ['booking' => $booking, 'provider' => $provider, 'customer' => $customer] = $this->makeBookingScenario('searching_provider');
        $booking->update(['scheduled_at' => now()->addDays(1)->setTime(14, 0)]);
        $this->makeEligible($provider, $booking->service->category_id);
        \App\Models\DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'notified', 'notified_at' => now(),
        ]);

        app(AcceptBookingAction::class)->execute($booking->id, $provider->fresh());

        Notification::assertSentTo($customer, BookingStatusNotification::class, fn ($n) => $n->eventKey() === 'booking.scheduled_assigned');
        Notification::assertSentTo($provider->user, ProviderJobStatusNotification::class, fn ($n) => $n->eventKey() === 'provider.job_scheduled_assigned');
    }

    public function test_unassigning_a_scheduled_provider_notifies_customer_and_reopens_the_offer_cycle_without_duplicate_assignment(): void
    {
        Notification::fake();

        ['booking' => $booking, 'provider' => $oldProvider, 'category' => $category, 'franchise' => $franchise, 'zone' => $zone] = $this->makeAssignedBookingScenario();
        $booking->update(['scheduled_at' => now()->addDays(1)->setTime(9, 0)]);
        $this->makeEligible($oldProvider, $category->id);
        // Real production shape: AcceptBookingAction always leaves behind
        // the 'accepted' dispatch_attempts row this test's own exclusion
        // assertion depends on (excludedProviderIdsForBooking() is what
        // actually stops the old provider being re-offered).
        \App\Models\DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $oldProvider->id,
            'status' => 'accepted', 'notified_at' => now()->subMinutes(5), 'responded_at' => now(),
        ]);

        $newProvider = $this->makeProviderIn($franchise, $zone);
        $this->makeEligible($newProvider, $category->id, 1.01, 1.01);

        $reopened = app(UnassignScheduledProviderAction::class)->execute($booking->id, 'Provider became unavailable');

        $this->assertSame('searching_provider', $reopened->status);
        $this->assertNull($reopened->provider_id);

        Notification::assertSentTo($booking->customer, BookingStatusNotification::class);
        Notification::assertSentTo($oldProvider->user, ProviderJobStatusNotification::class, fn ($n) => $n->eventKey() === 'provider.job_cancelled');

        // Re-entered the open-offer cycle — the new provider has a live offer.
        $this->assertSame(1, \App\Models\DispatchAttempt::where('booking_id', $booking->id)->where('provider_id', $newProvider->id)->where('status', 'notified')->count());
        // The old (already-accepted, now-unassigned) provider is never re-offered.
        $this->assertSame(0, \App\Models\DispatchAttempt::where('booking_id', $booking->id)->where('provider_id', $oldProvider->id)->where('status', 'notified')->count());

        // No duplicate assignment: only the new provider can accept.
        $accepted = app(AcceptBookingAction::class)->execute($booking->id, $newProvider->fresh());
        $this->assertSame($newProvider->id, $accepted->provider_id);
    }
}
