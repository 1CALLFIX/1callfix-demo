<?php

namespace App\Services;

use App\Actions\AdminCancelBookingAction;
use App\Models\Booking;
use App\Models\Setting;
use App\Notifications\BookingStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Support\BookingSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * REF 1CF-SCHEDULING-DISPATCH-001 — the scheduled-booking counterpart to
 * DispatchDeadlineSweepService (which is, and stays, ASAP-only). Three
 * independent passes, each keyed off `scheduled_at` rather than
 * `dispatch_deadline_at`:
 *
 *   early warning  (scheduled_at - early_warning_hours, default 3h) —
 *                  still unassigned: alert admin + tell the customer
 *                  "still finding your professional".
 *   urgent alert   (scheduled_at - buffer, the SAME unified Part-1
 *                  customer-scheduling buffer, no second setting) —
 *                  still unassigned: admin-only urgent alert.
 *   auto-cancel    (scheduled_at itself) — still unassigned: cancel with
 *                  zero fee via the EXISTING AdminCancelBookingAction
 *                  (refund routing / Main Wallet rules / entitlement
 *                  reversal / bundle settlement all reused, nothing
 *                  duplicated), UNLESS admin has disabled auto-cancel
 *                  (dispatch.scheduled_auto_cancel_enabled = '0'), in
 *                  which case this pass is a no-op — the booking simply
 *                  stays escalated for manual admin handling.
 *
 * LATE-CREATED BOOKINGS: a milestone whose time has already passed when a
 * booking is first swept still fires exactly once, on the next run — no
 * special-casing needed, since each pass is just "threshold reached AND
 * not yet marked", the same idiom DispatchDeadlineSweepService already
 * uses. This is the opposite of ScheduledBookingReminderService's own
 * late-ASSIGNMENT rule (which SKIPS a passed reminder instead) — see that
 * class's docblock for why the two are deliberately different.
 */
class ScheduledBookingEscalationService
{
    public function __construct(
        private AdminOpsAlertService $opsAlerts,
        private AdminCancelBookingAction $cancelAction,
    ) {
    }

    /** @return array{early_warning: int, urgent: int, auto_cancelled: int} */
    public function sweep(): array
    {
        return [
            'early_warning' => $this->earlyWarnings(),
            'urgent' => $this->urgentAlerts(),
            'auto_cancelled' => $this->autoCancel(),
        ];
    }

    private function earlyWarningHours(): int
    {
        return (int) Setting::get('dispatch.scheduled_early_warning_hours', 3);
    }

    /** The ONE unified Part-1 customer-scheduling buffer — no second setting for this. */
    private function bufferMinutes(): int
    {
        return BookingSchedule::bufferMinutes();
    }

    private function autoCancelEnabled(): bool
    {
        return Setting::get('dispatch.scheduled_auto_cancel_enabled', '1') === '1';
    }

    private function earlyWarnings(): int
    {
        $count = 0;

        Booking::query()
            ->where('status', 'searching_provider')
            ->whereNotNull('scheduled_at')
            ->whereNull('scheduled_early_warning_at')
            ->where('scheduled_at', '<=', now()->addHours($this->earlyWarningHours()))
            ->pluck('id')
            ->each(function (int $id) use (&$count) {
                if ($this->fireEarlyWarning($id)) {
                    $count++;
                }
            });

        return $count;
    }

    private function fireEarlyWarning(int $id): bool
    {
        $booking = DB::transaction(function () use ($id) {
            $locked = Booking::lockForUpdate()->find($id);

            if (! $locked
                || $locked->status !== 'searching_provider'
                || $locked->scheduled_early_warning_at !== null
                || $locked->provider_id !== null
                || ! $locked->scheduled_at
                || $locked->scheduled_at->gt(now()->addHours($this->earlyWarningHours()))
            ) {
                return null;
            }

            $locked->scheduled_early_warning_at = now();
            $locked->save();

            return $locked->fresh();
        });

        if (! $booking) {
            return false;
        }

        $this->opsAlerts->scheduledEarlyWarning($booking);

        if ($booking->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);

            try {
                $booking->customer->notify(new BookingStatusNotification('scheduled_still_searching', $booking, $channels));
            } catch (\Throwable $e) {
                Log::error("ScheduledBookingEscalationService: failed to deliver early-warning customer notice for booking [{$booking->id}]: ".$e->getMessage());
            }
        }

        Log::info("ScheduledBookingEscalationService: early-warning fired for scheduled booking [{$booking->id}].");

        return true;
    }

    private function urgentAlerts(): int
    {
        $count = 0;

        Booking::query()
            ->where('status', 'searching_provider')
            ->whereNotNull('scheduled_at')
            ->whereNull('scheduled_urgent_alert_at')
            ->where('scheduled_at', '<=', now()->addMinutes($this->bufferMinutes()))
            ->pluck('id')
            ->each(function (int $id) use (&$count) {
                if ($this->fireUrgentAlert($id)) {
                    $count++;
                }
            });

        return $count;
    }

    private function fireUrgentAlert(int $id): bool
    {
        $booking = DB::transaction(function () use ($id) {
            $locked = Booking::lockForUpdate()->find($id);

            if (! $locked
                || $locked->status !== 'searching_provider'
                || $locked->scheduled_urgent_alert_at !== null
                || $locked->provider_id !== null
                || ! $locked->scheduled_at
                || $locked->scheduled_at->gt(now()->addMinutes($this->bufferMinutes()))
            ) {
                return null;
            }

            $locked->scheduled_urgent_alert_at = now();
            $locked->save();

            return $locked->fresh();
        });

        if (! $booking) {
            return false;
        }

        $this->opsAlerts->scheduledUrgentAlert($booking);

        Log::warning("ScheduledBookingEscalationService: urgent alert fired for scheduled booking [{$booking->id}].");

        return true;
    }

    private function autoCancel(): int
    {
        if (! $this->autoCancelEnabled()) {
            return 0;
        }

        $count = 0;

        Booking::query()
            ->where('status', 'searching_provider')
            ->whereNotNull('scheduled_at')
            ->whereNull('provider_id')
            ->where('scheduled_at', '<=', now())
            ->pluck('id')
            ->each(function (int $id) use (&$count) {
                if ($this->cancelOne($id)) {
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Same "verify fresh under lock, then cancel outside the transaction
     * boundary that matters" shape as
     * DispatchDeadlineSweepService::cancelOne() — see that method's own
     * docblock for the full race-safety/refund-failure-survives-the-
     * cancellation rationale, unchanged here.
     */
    private function cancelOne(int $id): bool
    {
        $stillDue = DB::transaction(function () use ($id) {
            $locked = Booking::lockForUpdate()->find($id);

            return $locked
                && $locked->status === 'searching_provider'
                && $locked->provider_id === null
                && $locked->scheduled_at
                && $locked->scheduled_at->lte(now());
        });

        if (! $stillDue) {
            return false;
        }

        try {
            // creditToMainWallet = true — same finalized business decision
            // DispatchDeadlineSweepService's own T+30 no-provider-found
            // cancellation applies (REF 1CF-IMPLEMENT-20260923-MAIN-WALLET);
            // calculateFee() already returns 0 whenever provider_id is
            // null (CancellationService's own documented rule), which is
            // exactly this case, so "cancellation fee = zero" needs no
            // separate enforcement here.
            $this->cancelAction->execute($id, 'Scheduled booking reached its scheduled time with no provider assigned — automatically cancelled.', true, 'scheduled_unassigned_cancelled', true);
        } catch (\Throwable $e) {
            Log::error("ScheduledBookingEscalationService: booking [{$id}] auto-cancel-at-scheduled-time failed: ".$e->getMessage());

            return false;
        }

        Log::warning("ScheduledBookingEscalationService: auto-cancelled scheduled booking [{$id}] at its scheduled_at with no provider assigned.");

        return true;
    }
}
