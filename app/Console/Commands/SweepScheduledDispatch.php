<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\ScheduledBookingEscalationService;
use App\Services\ScheduledBookingReminderService;
use App\Services\ScheduledDispatchService;
use Illuminate\Console\Command;

/**
 * REF 1CF-SCHEDULING-DISPATCH-001 — the scheduler backstop the discovery
 * doc called for: "a lost delayed queue job cannot permanently lose the
 * scheduled booking". Four idempotent passes, every one of them safe to
 * run repeatedly and safe to run out of order:
 *
 *   1. Release any scheduled booking that got paid but never had its
 *      offers actually sent — covers a crashed/lost call to
 *      ScheduledDispatchService::releaseIfEligible() at booking creation
 *      or webhook time.
 *   2. For every still-open scheduled booking: catch newly-eligible
 *      providers (idempotent — only ever offers to providers not already
 *      offered) and send the re-offer reminder if its interval is due.
 *   3. Escalation: early-warning / urgent-alert / auto-cancel-at-
 *      scheduled_at (ScheduledBookingEscalationService).
 *   4. Provider T-60/T-30 reminders (ScheduledBookingReminderService).
 *
 * Same registration idiom as dispatch:sweep-deadlines — see
 * routes/console.php.
 */
class SweepScheduledDispatch extends Command
{
    protected $signature = 'dispatch:sweep-scheduled';

    protected $description = 'Catches up scheduled-booking open-offer dispatch, re-offers, and escalation/reminders';

    public function handle(
        ScheduledDispatchService $scheduledDispatch,
        ScheduledBookingEscalationService $escalation,
        ScheduledBookingReminderService $reminders,
    ): int {
        $released = 0;

        Booking::query()
            ->where('status', 'pending')
            ->whereNotNull('scheduled_at')
            ->where('payment_status', 'paid')
            ->whereNull('scheduled_offers_sent_at')
            ->pluck('id')
            ->each(function (int $id) use ($scheduledDispatch, &$released) {
                $booking = Booking::find($id);
                if ($booking) {
                    $scheduledDispatch->releaseIfEligible($booking);
                    $released++;
                }
            });

        $newOffers = 0;
        $reoffered = 0;

        Booking::query()
            ->where('status', 'searching_provider')
            ->whereNotNull('scheduled_at')
            ->whereNull('provider_id')
            ->pluck('id')
            ->each(function (int $id) use ($scheduledDispatch, &$newOffers, &$reoffered) {
                $booking = Booking::find($id);
                if (! $booking) {
                    return;
                }
                $newOffers += $scheduledDispatch->sendOpenOffers($booking);
                if ($scheduledDispatch->sendReminderIfDue($booking)) {
                    $reoffered++;
                }
            });

        $escalationResult = $escalation->sweep();
        $remindersSent = $reminders->sendDueReminders();

        $this->info(
            "Scheduled dispatch sweep: {$released} released, {$newOffers} new offer(s), {$reoffered} re-offer round(s), "
            ."{$escalationResult['early_warning']} early-warning, {$escalationResult['urgent']} urgent, "
            ."{$escalationResult['auto_cancelled']} auto-cancelled, {$remindersSent} provider reminder(s)."
        );

        return self::SUCCESS;
    }
}
