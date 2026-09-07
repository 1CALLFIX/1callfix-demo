<?php

namespace App\Actions;

use App\Events\BookingStatusUpdated;
use App\Models\Booking;
use App\Models\Provider;
use App\Notifications\ProviderJobStatusNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase PN1 — "I'm on my way". The `provider_en_route` status has existed
 * in the FSM vocabulary since Phase 1 (StatusPresenter, StuckBookingService's
 * 60-min threshold, and the completable-from / holdable-from / startable-from
 * sets in CompleteBookingAction / PlaceBookingOnHoldAction / StartBookingAction
 * all already accept it) but nothing has ever transitioned a booking INTO
 * it — there was no provider-facing action for it. This is that action.
 *
 * Deliberately the same shape as StartBookingAction, minus the OTP: a single
 * locked forward transition, a status-history row, the BookingStatusUpdated
 * event the customer/provider surfaces already listen for, and one
 * post-commit provider notification (guarded and logged, never able to roll
 * back the committed transition — same convention as AcceptBookingAction's
 * own notification sends).
 *
 * `assigned` is the only state it accepts: en route is the step between
 * accepting and starting. StartBookingAction already treats
 * `provider_en_route` as startable, so a provider can still skip straight to
 * Start exactly as before — this never becomes a required step.
 */
class MarkEnRouteAction
{
    private const FROM_STATUSES = ['assigned'];

    /** @throws \RuntimeException if the booking isn't the provider's, or isn't in a state that can go en route */
    public function execute(int $bookingId, Provider $provider, ?int $changedByUserId = null): Booking
    {
        $booking = DB::transaction(function () use ($bookingId, $provider, $changedByUserId) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if ($booking->provider_id !== $provider->id) {
                throw new \RuntimeException('This booking is not assigned to you.');
            }

            if (! in_array($booking->status, self::FROM_STATUSES, true)) {
                throw new \RuntimeException(
                    "Booking [{$bookingId}] cannot be marked en route from status '{$booking->status}'."
                );
            }

            $booking->status = 'provider_en_route';
            $booking->save();

            $booking->statusHistory()->create([
                'status' => 'provider_en_route',
                'changed_by' => $changedByUserId ?? $provider->user_id,
                'note' => 'Provider marked en route',
                'changed_at' => now(),
            ]);

            event(new BookingStatusUpdated($booking));

            return $booking->fresh();
        });

        $this->notifyProviderOfStatus($booking, 'en_route');

        return $booking;
    }

    /**
     * Post-commit, guarded, logged — a notification transport failure must
     * never roll back or corrupt the already-committed transition. Same
     * pattern AcceptBookingAction::sendStatusNotification() uses.
     */
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
