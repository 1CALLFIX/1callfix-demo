<?php

namespace App\Actions;

use App\Events\BookingStatusUpdated;
use App\Models\Booking;
use App\Notifications\ProviderJobStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\Cancellation\SparesDeclaration;
use App\Services\Cancellation\SparesDelayClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlaceBookingOnHoldAction
{
    private const CUSTOMER_SIDE_REASONS = [
        'awaiting_spares',
        'awaiting_customer_approval',
        'awaiting_payment_decision',
        'other_customer_issue',
    ];

    private const PROVIDER_SIDE_REASONS = [
        'provider_unresponsive',
        'payment_not_reconciled',
        'other_provider_issue',
    ];

    /**
     * Puts an in-progress booking on hold. The category (customer_side vs.
     * provider_side) is derived automatically from the reason, not passed in
     * separately — this guarantees a booking can never end up mis-categorized
     * by a caller passing an inconsistent category/reason pair.
     *
     * customer_side: routine, provider stays assigned, follow-up call goes to
     *   the customer to unblock it.
     * provider_side: red flag, urgent — surfaces on a priority admin queue,
     *   follow-up call goes to the provider. Feeds into provider reliability
     *   tracking (rating_avg / provider_badges) in a later phase.
     *
     * @throws \InvalidArgumentException for an unrecognized reason
     * @throws \RuntimeException if the booking isn't in a holdable state
     */
    /**
     * @param  ?array  $spares  REF 1CF-CANCEL-POLICY-001 — for `awaiting_spares`: the professional's declaration
     *        (one capped amount for work done, optional evidence, expected arrival date, who sources the part), see SparesDeclaration.
     *        Provider-facing callers MUST pass it; an operator hold without one is allowed and simply carries
     *        no interim-work declaration (so a later cancellation of a started job charges nothing).
     */
    public function execute(int $bookingId, string $reason, ?string $note = null, ?array $spares = null): Booking
    {
        $category = match (true) {
            in_array($reason, self::CUSTOMER_SIDE_REASONS, true) => 'customer_side',
            in_array($reason, self::PROVIDER_SIDE_REASONS, true) => 'provider_side',
            default => throw new \InvalidArgumentException("Unrecognized hold reason: {$reason}"),
        };

        $declaredEarlyUnlock = false;

        $booking = DB::transaction(function () use ($bookingId, $reason, $note, $category, $spares, &$declaredEarlyUnlock) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if (!in_array($booking->status, ['assigned', 'provider_en_route', 'in_progress'], true)) {
                throw new \RuntimeException(
                    "Booking [{$bookingId}] cannot be put on hold from status '{$booking->status}' — " .
                    "only active, assigned bookings can be held."
                );
            }

            $booking->status = 'on_hold';
            $booking->hold_category = $category;
            $booking->hold_reason = $reason;
            $booking->hold_note = $note;
            $booking->on_hold_since = now();

            $sourceTag = '';
            if ($reason === 'awaiting_spares' && $spares !== null) {
                $declared = SparesDeclaration::normalise($booking, $spares);
                $booking->interim_amount = $declared['work_amount'];
                $booking->interim_evidence = $declared['evidence'] ?: null;
                $booking->spares_expected_at = $declared['expected_at'];
                $booking->spares_sourced_by = $declared['sourced_by'];
                $booking->interim_declared_at = now();
                // A fresh declaration re-opens the dispute window; an earlier resolved dispute does not carry over.
                $booking->interim_dispute_status = null;
                $booking->interim_disputed_at = null;
                $booking->interim_dispute_note = null;
                $sourceTag = " [src={$declared['sourced_by']}]";
                $declaredEarlyUnlock = app(SparesDelayClock::class)->earlyUnlocked($booking);
            } elseif ($reason === 'awaiting_spares') {
                $booking->spares_sourced_by = null;
                $booking->spares_expected_at = null;
            }

            $booking->save();

            $booking->statusHistory()->create([
                'status' => 'on_hold',
                'changed_by' => $booking->provider?->user_id,
                'note' => "Hold reason: {$reason}" . ($note ? " — {$note}" : '') . $sourceTag,
                'changed_at' => now(),
            ]);

            event(new BookingStatusUpdated($booking));

            return $booking->fresh();
        });

        // Phase PN1 — post-commit provider notification (job paused).
        // Guarded + logged; cannot roll back the committed hold.
        $this->notifyProviderOfStatus($booking, 'on_hold');
        \App\Support\Journey\StageNotifier::customer($booking, 'on_hold');

        if ($reason === 'awaiting_spares' && $spares !== null) {
            // Show the customer what was declared (and the arrival date) straight away; if the date alone already
            // exceeds the threshold, tell them they can cancel right now under the same interim-work charge.
            \App\Support\Journey\StageNotifier::customer($booking, $declaredEarlyUnlock ? 'spares_early_unlock' : 'spares_declared');
        }

        return $booking;
    }

    private function notifyProviderOfStatus(Booking $booking, string $event): void
    {
        $user = $booking->provider?->user;

        if (! $user) {
            return;
        }

        $channels = ChannelResolver::resolve(array_filter([
            'zone_id' => $booking->zone_id,
            'franchise_id' => $booking->franchise_id,
        ]));

        try {
            $user->notify(new ProviderJobStatusNotification($event, $booking, $channels));
        } catch (\Throwable $e) {
            Log::error("Failed to deliver provider '{$event}' notification for booking [{$booking->id}]: ".$e->getMessage());
        }
    }
}
