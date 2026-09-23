<?php

namespace App\Jobs;

use App\Events\BookingStatusUpdated;
use App\Events\NewJobOffered;
use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\Setting;
use App\Notifications\ProviderJobOfferNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\DispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The dispatch engine. Adapted from the real matching pattern found in Glover's
 * RegularOrderMatchingJob, but running against our own MySQL database (via
 * DispatchService) instead of Firebase Firestore, and scoped by zone.
 *
 * Flow per run:
 *   1. Bail early if the booking is no longer searching (already assigned/cancelled).
 *   2. Ask DispatchService for up to BATCH_SIZE nearest eligible providers who
 *      haven't already been offered this booking.
 *   3. If none found and we've hit MAX_ATTEMPTS_ROUNDS, give up — leave the
 *      booking in `searching_provider` so it surfaces on the admin's live queue
 *      for manual assignment. This is the deliberate fallback, not a crash.
 *      (Round increments on EVERY re-dispatch, including "found nobody this
 *      time" — not just after actually making offers, or this cap never fires.)
 *   4. Otherwise, create a `dispatch_attempts` row per candidate and broadcast
 *      NewJobOffered to each — this is what wakes up the provider app.
 *   5. Mark this round's prior (not-yet-timed-out) attempts as `timeout` if
 *      their offer window has expired, then re-queue itself after a short
 *      delay — the "keep re-broadcasting until someone accepts" behavior.
 */
class ServiceMatchingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Tuning values below are admin-editable via the Settings screen
     * (Setting::get, cached) — same defaults as before that screen existed,
     * so behaviour is unchanged until an admin actually edits one.
     */

    /** How many providers get offered the job simultaneously, per round. */
    private function batchSize(): int
    {
        return (int) Setting::get('dispatch.offer_batch_size', 5);
    }

    /** How long a single offer stays open before being marked timed out. */
    private function offerTimeoutSeconds(): int
    {
        return (int) Setting::get('dispatch.offer_timeout_seconds', 25);
    }

    /** Safety cap so a booking with zero available providers doesn't loop forever. */
    private function maxRounds(): int
    {
        return (int) Setting::get('dispatch.max_rounds', 6);
    }

    public function __construct(
        public int $bookingId,
        public int $round = 1,
    ) {
    }

    public function handle(DispatchService $dispatchService): void
    {
        // Claim the booking under a row lock before touching status — every
        // other booking-mutating Action in this app (AcceptBookingAction,
        // CompleteBookingAction, AdminCancelBookingAction, ...) already does
        // this; this job's own pending -> searching_provider transition was
        // the one exception, and an unlocked read-then-write here could lose
        // a concurrent, legitimate AcceptBookingAction's result in a classic
        // read-then-blind-write race (found live during Phase B0.3
        // verification, investigated, and fixed here — see
        // PHASE_B0_3_DISPATCH_POLYMORPHISM.md's follow-up notes). The lock is
        // released as soon as this transaction commits; nothing below it
        // needs to stay inside it.
        $result = DB::transaction(function () {
            $booking = Booking::lockForUpdate()->find($this->bookingId);

            // Booking was cancelled, or already got assigned another way
            // (e.g. a real acceptance, or manual admin assignment) between
            // rounds — stop here. Checked AFTER acquiring the lock, not
            // before, so this can't act on stale data.
            if (!$booking || $booking->status !== 'searching_provider' && $booking->status !== 'pending') {
                return null;
            }

            $justStartedSearching = false;

            if ($booking->status === 'pending') {
                $booking->status = 'searching_provider';
                // REF 1CF-IMPLEMENT-20260922-L01 — set once, here, at the
                // real transition; never recomputed on a later round. This
                // is the sole write site DispatchDeadlineSweepService reads
                // to decide T+5/T+30 for this booking.
                $booking->dispatch_deadline_at = now();
                $booking->save();

                $booking->statusHistory()->create([
                    'status' => 'searching_provider',
                    'note' => 'Dispatch started — searching for an eligible provider',
                    'changed_at' => now(),
                ]);

                $justStartedSearching = true;
            }

            return [$booking->fresh(), $justStartedSearching];
        });

        [$booking, $justStartedSearching] = $result ?? [null, false];

        if (!$booking) {
            return;
        }

        // Only fire on the real pending -> searching_provider transition —
        // matches the original behavior exactly; later rounds (already
        // searching_provider) don't re-fire this event.
        if ($justStartedSearching) {
            event(new BookingStatusUpdated($booking));
        }

        // Close out any offers from the previous round whose window has expired
        // and nobody responded to.
        $this->timeoutExpiredAttempts($booking);

        $maxRounds = $this->maxRounds();

        if ($this->round > $maxRounds) {
            Log::warning("ServiceMatchingJob: exhausted {$maxRounds} rounds for booking [{$booking->id}] with no acceptance — leaving for manual admin assignment.");
            return;
        }

        $offerTimeoutSeconds = $this->offerTimeoutSeconds();
        $candidates = $dispatchService->findCandidates($booking, $this->batchSize());

        if ($candidates->isEmpty()) {
            // Round DOES increment here too -- confirmed bug, found via the
            // queue infrastructure audit: leaving it unincremented meant
            // maxRounds() (whose own docblock promises "doesn't loop
            // forever") never actually fired for a booking with zero
            // eligible providers, since $this->round would stay at 1
            // forever and never exceed $maxRounds. Reproduced live: booking
            // #10 looped every offerTimeoutSeconds indefinitely once a
            // worker actually started consuming the queue.
            self::dispatch($this->bookingId, $this->round + 1)
                ->delay(now()->addSeconds($offerTimeoutSeconds));
            return;
        }

        foreach ($candidates as $candidate) {
            $attempt = DispatchAttempt::create([
                'booking_id' => $booking->id,
                'provider_id' => $candidate['provider']->id,
                'status' => 'notified',
                'distance_km' => $candidate['distance_km'],
                'notified_at' => now(),
            ]);

            event(new NewJobOffered($booking, $attempt));

            // FCM push — covers the app-closed / phone-locked case the
            // WebSocket broadcast above cannot. Notifiable is the provider's
            // own User (Provider has no fcm_token); no-ops for a provider
            // who hasn't registered a web token. ShouldQueue, so the FCM
            // call doesn't block this dispatch round.
            optional($candidate['provider']->user)->notify(new ProviderJobOfferNotification(
                $booking,
                ChannelResolver::resolve(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]),
            ));
        }

        // Re-check after the offer window closes — if nobody's accepted by then,
        // this same job body runs again: times out this round's attempts and
        // tries the next batch of candidates.
        self::dispatch($this->bookingId, $this->round + 1)
            ->delay(now()->addSeconds($offerTimeoutSeconds));
    }

    private function timeoutExpiredAttempts(Booking $booking): void
    {
        $booking->dispatchAttempts()
            ->where('status', 'notified')
            ->where('notified_at', '<=', now()->subSeconds($this->offerTimeoutSeconds()))
            ->update(['status' => 'timeout', 'responded_at' => now()]);
    }

    /**
     * REF 1CF-IMPLEMENT-20260922-L01 — L-02 minimum. A queue failure that
     * exhausts this job's retries WITHOUT it ever requeuing itself again
     * leaves a booking sitting in `searching_provider` looking identical,
     * at T+5/T+30, to a booking that genuinely searched and found nobody —
     * dispatch_attempts may even be empty in both cases (zero eligible
     * providers produces the same empty set a crashed first round does).
     * This is the one distinguishing signal: a plain booking_status_history
     * row, greppable by DispatchDeadlineSweepService before it decides the
     * T+5 alert copy / T+30 cancellation reason, so a job-failure scenario
     * never silently reads as "no provider was available" to an admin or
     * the customer. Deliberately NOT a full recovery mechanism (no retry,
     * no re-queue, no new booking-status/column) — the fuller fix (a
     * failed-job requeue/alert pipeline) is a bigger change than this
     * task's scope allows; noted as a follow-up in the implementation
     * report.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("ServiceMatchingJob failed permanently for booking [{$this->bookingId}] round {$this->round}: {$exception->getMessage()}");

        $booking = Booking::find($this->bookingId);

        if ($booking && in_array($booking->status, ['pending', 'searching_provider'], true)) {
            $booking->statusHistory()->create([
                'status' => $booking->status,
                'note' => 'Dispatch job failed permanently — automated re-dispatch stopped due to a queue/job error, not a lack of available providers.',
                'changed_at' => now(),
            ]);
        }
    }
}
