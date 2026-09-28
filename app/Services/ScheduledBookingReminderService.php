<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Setting;
use App\Notifications\ProviderJobStatusNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * REF 1CF-SCHEDULING-DISPATCH-001 (Part 3, provider reminders) — the two
 * admin-configurable "coming up" reminders sent to the assigned provider
 * before a scheduled booking's scheduled_at (defaults T-60/T-30 minutes).
 * bookings.scheduled_reminder_1_at / scheduled_reminder_2_at are the
 * idempotency guard: a scheduler tick only ever fires a milestone once.
 *
 * LATE-ASSIGNMENT RULE (brief, verbatim): "If a provider is assigned after
 * a reminder time has passed (for example assigned 40 minutes before the
 * slot), skip only the missed reminder." Concretely: if scheduled_at minus
 * an offset has ALREADY passed at the moment a provider is assigned, that
 * one reminder is never sent at all — markPassedMilestonesAsSkipped()
 * stamps its column immediately (at assignment) so sendDueReminders()
 * later sees "already settled" and moves on, rather than firing a
 * "1 hour to go" reminder with 40 minutes actually left. This is
 * deliberately different from the escalation milestones (early-warning /
 * urgent alert), which DO fire once, late, if they're already overdue when
 * the booking is created — see ScheduledBookingEscalationService's own
 * docblock for that distinction.
 */
class ScheduledBookingReminderService
{
    public function offset1Minutes(): int
    {
        return (int) Setting::get('booking.scheduled_reminder_offset_1_minutes', 60);
    }

    public function offset2Minutes(): int
    {
        return (int) Setting::get('booking.scheduled_reminder_offset_2_minutes', 30);
    }

    /**
     * Called once, right after AcceptBookingAction/AdminReassignBookingAction
     * assigns a provider to a scheduled booking. For each of the two
     * reminder milestones whose fire time (scheduled_at - offset) has
     * already passed, stamps the column now — settling it as "handled"
     * without ever sending anything for it.
     */
    public function markPassedMilestonesAsSkipped(Booking $booking): void
    {
        if ($booking->scheduled_at === null) {
            return;
        }

        $now = now();
        $updates = [];

        if ($booking->scheduled_reminder_1_at === null
            && $booking->scheduled_at->copy()->subMinutes($this->offset1Minutes())->lte($now)) {
            $updates['scheduled_reminder_1_at'] = $now;
        }

        if ($booking->scheduled_reminder_2_at === null
            && $booking->scheduled_at->copy()->subMinutes($this->offset2Minutes())->lte($now)) {
            $updates['scheduled_reminder_2_at'] = $now;
        }

        if ($updates !== []) {
            $booking->update($updates);
        }
    }

    /** @return int how many reminders (across both milestones) were sent this run */
    public function sendDueReminders(): int
    {
        $sent = 0;
        $sent += $this->sendDueForMilestone('scheduled_reminder_1_at', $this->offset1Minutes(), 'reminder_60');
        $sent += $this->sendDueForMilestone('scheduled_reminder_2_at', $this->offset2Minutes(), 'reminder_30');

        return $sent;
    }

    private function sendDueForMilestone(string $column, int $offsetMinutes, string $event): int
    {
        $count = 0;

        Booking::query()
            ->whereIn('status', ['assigned', 'provider_en_route'])
            ->whereNotNull('scheduled_at')
            ->whereNotNull('provider_id')
            ->whereNull($column)
            ->where('scheduled_at', '<=', now()->addMinutes($offsetMinutes))
            ->pluck('id')
            ->each(function (int $id) use ($column, $event, &$count) {
                if ($this->fireOne($id, $column, $event)) {
                    $count++;
                }
            });

        return $count;
    }

    private function fireOne(int $id, string $column, string $event): bool
    {
        $booking = DB::transaction(function () use ($id, $column) {
            $locked = Booking::lockForUpdate()->find($id);

            if (! $locked
                || $locked->{$column} !== null
                || $locked->provider_id === null
                || ! in_array($locked->status, ['assigned', 'provider_en_route'], true)
            ) {
                return null;
            }

            $locked->{$column} = now();
            $locked->save();

            return $locked->fresh();
        });

        if (! $booking) {
            return false;
        }

        $user = $booking->provider?->user;

        if ($user) {
            $channels = ChannelResolver::resolve(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);

            try {
                $user->notify(new ProviderJobStatusNotification($event, $booking, $channels));
            } catch (\Throwable $e) {
                Log::error("ScheduledBookingReminderService: failed to deliver {$event} for booking [{$booking->id}]: ".$e->getMessage());
            }
        }

        return true;
    }
}
