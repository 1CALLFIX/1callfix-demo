<?php

namespace App\Services;

use App\Actions\AdminCancelBookingAction;
use App\Models\Booking;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * REF 1CF-IMPLEMENT-20260922-L01 — closes finding L-01 (Phase 2C section D):
 * a Service Booking (`bookings.status`) that never finds a provider used to
 * sit in `searching_provider` forever, invisible to anyone until a human
 * happened to notice it. This is the mutating counterpart to
 * StuckBookingService (which stays exactly what its own docblock says it
 * is — read-only reporting, never touches a row); this service is the
 * new, SEPARATE, deliberately mutating sweep run by `dispatch:sweep-deadlines`.
 *
 * Two independent passes per run, each keyed off the SAME anchor —
 * bookings.dispatch_deadline_at, set once by ServiceMatchingJob at the real
 * pending -> searching_provider transition (see its own docblock) and never
 * recomputed here:
 *
 *   T+5  (escalateOverdue)  — still searching_provider, not yet escalated:
 *                             fire ONE scoped admin alert
 *                             (AdminOpsAlertService::dispatchEscalation()),
 *                             mark dispatch_escalated_at so a later run
 *                             within the same T+5..T+30 window never
 *                             re-alerts.
 *   T+30 (cancelOverdue)    — still searching_provider: auto-cancel via the
 *                             existing AdminCancelBookingAction (refund/
 *                             entitlement/notification/bundle-settlement
 *                             logic all reused, none duplicated here).
 *
 * Scoped ONLY to Service Booking, deliberately — Phase 2C scoped L-01 to
 * `bookings.status`; Parcel/Taxi/Marketplace/Rental/Hotel each have their
 * own separate dispatch/fulfilment timing (or none) and are out of scope
 * for this pass (noted as a follow-up, not fixed here).
 *
 * REF 1CF-IMPLEMENT-20260923-MAIN-WALLET — cancelOne() passes
 * $creditToMainWallet=true into AdminCancelBookingAction::execute(), so a
 * T+30 refund lands in the customer's Main Wallet even for a real
 * Razorpay-paid booking (finalized business decision — see
 * CancellationService::refundIfPaid()'s own docblock). Scoped the same way
 * as the rest of this class: STANDALONE Service Bookings only. A bundle
 * child that separately reaches T+30 still settles through
 * BundleSettlementService::settleFromChildren() (called by
 * AdminCancelBookingAction itself when booking_bundle_id is set) — that
 * service has its OWN, separate gateway-vs-wallet branch and its own
 * bundle-level fee/refund math untouched by this change. Making a bundle
 * child's T+30 refund Main-Wallet-consistent too is a real, symmetric gap
 * (same shape, different code, its own dedicated E5.1/E7 test suite) —
 * deliberately left as a follow-up rather than folded in here.
 */
class DispatchDeadlineSweepService
{
    /** Marker written by ServiceMatchingJob::failed() — see that method's own docblock (L-02 minimum). */
    private const JOB_FAILURE_NOTE_PREFIX = 'Dispatch job failed permanently';

    public function __construct(
        private AdminOpsAlertService $opsAlerts,
        private AdminCancelBookingAction $cancelAction,
    ) {
    }

    /** @return array{escalated: int, cancelled: int} */
    public function sweep(): array
    {
        return [
            'escalated' => $this->escalateOverdue(),
            'cancelled' => $this->cancelOverdue(),
        ];
    }

    /** No existing Setting key for this — StuckBookingService's own thresholds start at "stuck", not "worth telling an admin about yet". New, narrow key. */
    private function escalationMinutes(): int
    {
        return (int) Setting::get('dispatch.escalation_minutes', 5);
    }

    /**
     * Deliberately REUSES StuckBookingService's own Setting key/default for
     * searching_provider (30 minutes) rather than inventing a second config
     * surface for what is, functionally, the same threshold an admin has
     * presumably already tuned — per the task's own instruction not to
     * duplicate duration settings StuckBookingService already owns.
     */
    private function cancellationMinutes(): int
    {
        return (int) Setting::get('operations.stuck_threshold_minutes.searching_provider', 30);
    }

    private function escalateOverdue(): int
    {
        $cutoff = now()->subMinutes($this->escalationMinutes());
        $count = 0;

        Booking::query()
            ->where('status', 'searching_provider')
            // REF 1CF-IMPLEMENT-20260923-F01 — instant bookings only
            // (scheduled_at null = ASAP); see cancelOverdue().
            ->whereNull('scheduled_at')
            ->whereNull('dispatch_escalated_at')
            ->whereNotNull('dispatch_deadline_at')
            ->where('dispatch_deadline_at', '<=', $cutoff)
            ->pluck('id')
            ->each(function (int $id) use ($cutoff, &$count) {
                if ($this->escalateOne($id, $cutoff)) {
                    $count++;
                }
            });

        return $count;
    }

    /**
     * One booking, one short transaction: lock, re-check every condition
     * fresh (a concurrent AcceptBookingAction or admin reassignment since
     * the query above ran must win cleanly), mark escalated, THEN fire the
     * alert. The alert send happens inside the lock deliberately — it's a
     * best-effort push (already individually try/caught inside
     * AdminOpsAlertService), not an external payment call, so holding the
     * row lock the extra moment it takes to queue a Notification is a
     * non-issue, unlike CancellationService's refund call.
     */
    private function escalateOne(int $id, Carbon $cutoff): bool
    {
        return DB::transaction(function () use ($id, $cutoff) {
            $booking = Booking::lockForUpdate()->find($id);

            if (! $booking
                || $booking->status !== 'searching_provider'
                || $booking->dispatch_escalated_at !== null
                || $booking->provider_id !== null
                || ! $booking->dispatch_deadline_at
                || $booking->dispatch_deadline_at->gt($cutoff)
            ) {
                return false;
            }

            $booking->dispatch_escalated_at = now();
            $booking->save();

            $jobFailed = $this->hadJobFailure($booking);
            $this->opsAlerts->dispatchEscalation($booking, $jobFailed);

            Log::info("DispatchDeadlineSweepService: escalated booking [{$booking->id}] at T+{$this->escalationMinutes()} (job_failed=".($jobFailed ? 'yes' : 'no').').');

            return true;
        });
    }

    private function cancelOverdue(): int
    {
        $cutoff = now()->subMinutes($this->cancellationMinutes());
        $count = 0;

        Booking::query()
            ->where('status', 'searching_provider')
            // REF 1CF-IMPLEMENT-20260923-F01 — a scheduled booking
            // (scheduled_at set, up to booking.max_schedule_days_ahead out)
            // still starts dispatch at creation, so without this a booking
            // for next week would be auto-cancelled 30 minutes after it was
            // made. Scheduled bookings are excluded entirely (business
            // decision); their own unassigned-handling is a separate
            // follow-up, not this sweep. scheduled_at is only ever written
            // at creation, so filtering here is not a stale check.
            ->whereNull('scheduled_at')
            ->whereNotNull('dispatch_deadline_at')
            ->where('dispatch_deadline_at', '<=', $cutoff)
            ->pluck('id')
            ->each(function (int $id) use ($cutoff, &$count) {
                if ($this->cancelOne($id, $cutoff)) {
                    $count++;
                }
            });

        return $count;
    }

    /**
     * The whole eligibility-check-and-cancel runs as ONE outer transaction
     * — including the nested call into AdminCancelBookingAction::execute()
     * (Laravel/MySQL/SQLite all support this via savepoints on the same
     * connection, so the row lock acquired below is held continuously
     * through to the actual `status = 'cancelled'` write, never released
     * and re-acquired in between). That continuity is what makes this
     * race-safe against a concurrent AcceptBookingAction: it either wins
     * the lock first (commits assigned before this transaction opens, so
     * the provider_id/status re-check below correctly skips it) or blocks
     * on this transaction's lock and, once released, sees the booking
     * already cancelled — AcceptBookingAction now re-checks
     * `status === 'searching_provider'` under its own lock (closed as part
     * of this same L-01 change; previously it only re-checked
     * provider_id-null, which a cancelled-with-no-provider booking still
     * satisfies).
     *
     * Deliberately NOT held across CancellationService::refundIfPaid()'s
     * external Razorpay call and the various notification sends — those
     * run inside AdminCancelBookingAction::execute() AFTER its own inner
     * transaction returns, i.e. after the state mutation this lock is
     * protecting, but still before this OUTER transaction commits and
     * releases the lock. Accepting a slightly longer lock hold here (an
     * infrequent, background, per-booking sweep) is the deliberate
     * trade-off against re-implementing refund/entitlement/notification
     * logic outside AdminCancelBookingAction, which the task explicitly
     * disallows.
     *
     * Because that inner transaction is NESTED inside this one, its
     * "commit" is only a SAVEPOINT release, not a durable commit — nothing
     * here is actually durable until this outer transaction itself
     * commits. refundIfPaid()'s Razorpay call has no try/catch of its own
     * and can throw; left uncaught, that exception would escape this
     * closure and make Laravel roll back the WHOLE outer transaction,
     * undoing the booking's already-"committed" cancelled status along
     * with the failed refund attempt — silently reverting the booking to
     * searching_provider past its own deadline and re-attempting (and
     * re-failing) the same refund every subsequent minute, forever,
     * without a distinct alert. The try/catch below is what stops that:
     * catching the failure here, before it escapes this closure, lets the
     * outer transaction commit normally — so the cancellation itself
     * always survives, even when the refund (or any other post-refund
     * step inside execute(), such as the customer/provider notifications
     * or entitlement reversal) does not. That matches this action's own
     * invariant for every other caller (admin's cancel button, customer
     * self-cancel): the cancellation persists independently of refund
     * success.
     */
    private function cancelOne(int $id, Carbon $cutoff): bool
    {
        return DB::transaction(function () use ($id, $cutoff) {
            $booking = Booking::lockForUpdate()->find($id);

            if (! $booking
                || $booking->status !== 'searching_provider'
                || $booking->provider_id !== null
                || ! $booking->dispatch_deadline_at
                || $booking->dispatch_deadline_at->gt($cutoff)
            ) {
                return false;
            }

            $jobFailed = $this->hadJobFailure($booking);

            $reason = $jobFailed
                ? 'Platform dispatch error — an automated dispatch (queue/job) failure prevented normal provider search; no provider was ever assigned.'
                : 'Platform dispatch failure — no provider could be found within the allotted dispatch window.';

            try {
                // REF 1CF-IMPLEMENT-20260923-MAIN-WALLET — finalized
                // business decision: a T+30 no-provider-found
                // auto-cancellation credits the customer's Main Wallet
                // even when the original payment was a real Razorpay
                // capture, rather than sending a real refund back to the
                // card/bank. See CancellationService::refundIfPaid()'s own
                // docblock for the full rationale and the exact scope
                // boundary (standalone bookings only — see this class's
                // own module docblock on why bundle children are handled
                // by BundleSettlementService instead, unchanged here).
                $this->cancelAction->execute($booking->id, $reason, true, 'no_provider_found', true);
            } catch (\Throwable $e) {
                // See this method's own docblock: caught here, not
                // rethrown, so the cancellation itself (already written by
                // execute()'s own nested transaction) survives this outer
                // transaction's commit even though a post-cancellation
                // step — almost certainly the refund — failed.
                Log::error("DispatchDeadlineSweepService: booking [{$booking->id}] auto-cancelled at T+{$this->cancellationMinutes()}, but a post-cancellation step (refund/notification/entitlement-reversal) failed: ".$e->getMessage());

                // Payment-failure visibility is a release safeguard, not
                // optional — a silently-caught refund failure still leaves
                // real money stuck needing manual reconciliation. Distinct
                // from dispatchEscalation()'s alerts: this fires regardless
                // of $jobFailed, because the cancellation already happened
                // either way — this alert is about the refund, not dispatch.
                $this->opsAlerts->autoCancelRefundFailed($booking);
            }

            Log::log($jobFailed ? 'error' : 'warning', "DispatchDeadlineSweepService: auto-cancelled booking [{$booking->id}] at T+{$this->cancellationMinutes()} (job_failed=".($jobFailed ? 'yes' : 'no').').');

            return true;
        });
    }

    /** REF 1CF-IMPLEMENT-20260922-L01 — L-02 minimum: see ServiceMatchingJob::failed()'s own docblock for why this note, not dispatch_attempts count (which is empty in BOTH a genuine zero-candidates round and a crashed first round), is the reliable signal. */
    private function hadJobFailure(Booking $booking): bool
    {
        return $booking->statusHistory()->where('note', 'like', self::JOB_FAILURE_NOTE_PREFIX.'%')->exists();
    }
}
