<?php

namespace App\Services;

use App\Events\NewJobOffered;
use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\Setting;
use App\Notifications\ProviderJobOfferNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * REF 1CF-SCHEDULING-DISPATCH-001 (Part 2) — "scheduled dispatch = open
 * offer / future commitment". The scheduled-booking counterpart to
 * ServiceMatchingJob, deliberately NOT a copy of it: no round timeout, no
 * batch-of-N re-broadcast, no max_rounds giving-up. An offer sent here
 * stays `notified` until a provider accepts (AcceptBookingAction, entirely
 * unchanged — "first valid acceptance wins" already works because that
 * Action locks the booking row and re-checks status before assigning), the
 * booking is cancelled, or admin/Action supersedes it.
 *
 * Every method here is safe to call repeatedly (idempotent) — that's what
 * lets the scheduler catch-up command (ScheduledDispatchCatchUp) drive
 * this instead of a fragile one-shot delayed job: a lost/failed queue job
 * can never permanently strand a scheduled booking's dispatch the way the
 * discovery doc found (NLR-2509-00000002).
 */
class ScheduledDispatchService
{
    public function __construct(private DispatchService $dispatchService)
    {
    }

    /**
     * PAYMENT GATE — a scheduled booking must not release ANY offer until
     * its payment is confirmed. Called from two sites, both idempotent
     * against each other:
     *   - CreateBookingAction::execute(), right after a wallet debit (which
     *     captures synchronously, in the same request);
     *   - RazorpayWebhookHandler::handleCaptured(), once the gateway
     *     confirms an online payment.
     * A cash scheduled booking is rejected earlier, at creation
     * (CreateBookingAction validates payment_method !== 'cash' when
     * scheduled_at is set) — cash's payment_status never reaches 'paid' on
     * its own, so without that validation this method would simply never
     * fire for a cash scheduled booking, silently stranding it forever.
     *
     * No-ops (returns without doing anything) unless the booking is a
     * still-`pending`, unpaid-until-now, scheduled booking whose offers
     * have never been released — every other state (already released,
     * already assigned, cancelled, ASAP/no scheduled_at) is a deliberate
     * no-op, not an error, so a caller never needs to pre-check.
     */
    public function releaseIfEligible(Booking $booking): void
    {
        if ($booking->scheduled_at === null) {
            return;
        }

        $released = DB::transaction(function () use ($booking) {
            $locked = Booking::lockForUpdate()->find($booking->id);

            if (! $locked
                || $locked->scheduled_at === null
                || $locked->status !== 'pending'
                || $locked->payment_status !== 'paid'
                || $locked->scheduled_offers_sent_at !== null
            ) {
                return null;
            }

            $locked->status = 'searching_provider';
            $locked->scheduled_offers_sent_at = now();
            $locked->scheduled_last_offer_at = now();
            // Deliberately NOT setting dispatch_deadline_at — that column
            // is the ASAP T+5/T+30 sweep's own anchor
            // (DispatchDeadlineSweepService), which already excludes every
            // scheduled booking. This booking's timeline is anchored to
            // scheduled_at instead (ScheduledBookingEscalationService).
            $locked->save();

            $locked->statusHistory()->create([
                'status' => 'searching_provider',
                'note' => 'Scheduled dispatch released — payment confirmed, sending open offers to eligible providers.',
                'changed_at' => now(),
            ]);

            event(new \App\Events\BookingStatusUpdated($locked));

            return $locked->fresh();
        });

        if ($released) {
            $this->sendOpenOffers($released);
        }
    }

    /**
     * Send (or catch up on) offers for an already-released, still-open
     * scheduled booking. Idempotent: excludedProviderIdsForBooking()
     * (inside findScheduledCandidates()) already excludes every provider
     * with a live/accepted/declined dispatch_attempts row for this
     * booking, so calling this again only ever offers to providers who
     * (a) never got an offer, or (b) just became newly eligible — exactly
     * the "newly eligible provider catch-up" behaviour the scheduler
     * command needs, with no separate code path.
     *
     * @return int how many NEW offers were created this call
     */
    public function sendOpenOffers(Booking $booking): int
    {
        $booking->refresh();

        if ($booking->status !== 'searching_provider' || $booking->scheduled_at === null || $booking->provider_id !== null) {
            return 0;
        }

        $candidates = $this->dispatchService->findScheduledCandidates($booking);

        if ($candidates->isEmpty()) {
            return 0;
        }

        $channels = ChannelResolver::resolve(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);

        foreach ($candidates as $candidate) {
            $attempt = DispatchAttempt::create([
                'booking_id' => $booking->id,
                'provider_id' => $candidate['provider']->id,
                'status' => 'notified',
                'distance_km' => $candidate['distance_km'],
                'notified_at' => now(),
            ]);

            event(new NewJobOffered($booking, $attempt));

            // Deliberately reaches an OFFLINE provider too (brief: "do not
            // require the provider to be online now if the existing
            // notification architecture can deliver the offer to an
            // offline provider") — FCM push already does exactly that; the
            // WebSocket broadcast above is simply a no-op for a
            // disconnected client, same as ServiceMatchingJob's own
            // identical call.
            optional($candidate['provider']->user)->notify(new ProviderJobOfferNotification($booking, $channels));
        }

        $booking->update(['scheduled_last_offer_at' => now()]);

        return $candidates->count();
    }

    /**
     * REPEATED PROVIDER OFFERS — re-push the reminder to every provider
     * whose offer is still open (status='notified', nobody has responded)
     * once the admin-configurable re-offer interval has elapsed since the
     * last send. Deliberately does NOT touch dispatch_attempts.notified_at
     * (that would make a since-declined/expired-looking attempt look
     * freshly issued) and does NOT create new rows — an open offer is one
     * row per provider for the life of the booking; this only re-notifies.
     *
     * Declined providers (status='rejected') are excluded automatically —
     * this only ever selects status='notified' rows.
     *
     * @return bool whether a reminder round actually went out
     */
    public function sendReminderIfDue(Booking $booking): bool
    {
        $booking->refresh();

        if ($booking->status !== 'searching_provider' || $booking->scheduled_at === null || $booking->provider_id !== null) {
            return false;
        }

        if ($booking->scheduled_last_offer_at === null) {
            return false;
        }

        $intervalHours = (int) Setting::get('dispatch.scheduled_reoffer_interval_hours', 2);

        if (now()->lt($booking->scheduled_last_offer_at->copy()->addHours($intervalHours))) {
            return false;
        }

        $openAttempts = $booking->dispatchAttempts()->where('status', 'notified')->with('provider.user')->get();

        if ($openAttempts->isEmpty()) {
            // Nobody currently holds an open offer (everyone declined, or
            // this booking somehow has zero attempts) — that's exactly
            // the "newly eligible provider" gap sendOpenOffers() closes,
            // not a reminder. Try that instead so the open-offer cycle
            // never goes silent just because every prior candidate
            // declined.
            return $this->sendOpenOffers($booking) > 0;
        }

        $channels = ChannelResolver::resolve(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);

        foreach ($openAttempts as $attempt) {
            optional($attempt->provider?->user)->notify(new ProviderJobOfferNotification($booking, $channels));
        }

        // Also catch any newly-eligible provider in the same pass — one
        // scheduler tick, both jobs done, no separate "catch-up" call
        // needed from the command.
        $this->sendOpenOffers($booking);

        $booking->update(['scheduled_last_offer_at' => now()]);

        Log::info("ScheduledDispatchService: re-offer reminder sent for scheduled booking [{$booking->id}] to ".$openAttempts->count().' open offer(s).');

        return true;
    }
}
