<?php

namespace App\Actions;

use App\Events\BookingStatusUpdated;
use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Notifications\BookingStatusNotification;
use App\Notifications\ProviderJobStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\ScheduledDispatchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * REF 1CF-SCHEDULING-DISPATCH-001 (Part 3) — the one gap this codebase had
 * no existing action for at all: putting an ALREADY-ASSIGNED scheduled
 * booking back into the open-offer cycle, for any of the brief's named
 * triggers —
 *
 *   - the assigned provider cancels (a provider-initiated cancellation of
 *     their own accepted job — no such self-service action exists in this
 *     codebase yet either; this Action is what a future one would call),
 *   - the provider becomes invalid/ineligible (KYC revoked, suspended,
 *     ...) — an admin/automated caller invokes this instead of leaving a
 *     doomed assignment in place,
 *   - an admin explicitly unassigns/replaces the provider.
 *
 * Deliberately scoped to SCHEDULED bookings only (scheduled_at not null,
 * status in the pre-service set) — an ASAP booking's assigned/en-route/
 * in-progress states have no equivalent "reopen and keep searching"
 * concept anywhere else in this app either, and inventing one for ASAP is
 * out of this phase's scope.
 *
 * Reuses ServiceMatchingJob... no — deliberately does NOT reuse
 * ServiceMatchingJob (the ASAP round engine): a scheduled booking's
 * reopened search is still an OPEN offer, so this hands it straight back
 * to ScheduledDispatchService::sendOpenOffers(), same as the original
 * release — "do not create duplicate assignment/acceptance mechanisms".
 * AcceptBookingAction's own lock/race-guard is completely untouched and
 * is exactly what makes the reopened booking safe to re-accept.
 */
class UnassignScheduledProviderAction
{
    /** Statuses a scheduled booking can be unassigned FROM — mirrors AdminCancelBookingAction::PRE_SERVICE_STATUSES minus 'pending'/'searching_provider' (which have no provider to unassign). */
    private const UNASSIGNABLE_STATUSES = ['assigned', 'provider_en_route'];

    public function __construct(private ScheduledDispatchService $scheduledDispatch)
    {
    }

    public function execute(int $bookingId, string $reason): Booking
    {
        $booking = DB::transaction(function () use ($bookingId, $reason) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if ($booking->scheduled_at === null) {
                throw new \RuntimeException('Only a scheduled booking can be returned to the open-offer cycle.');
            }

            if (! in_array($booking->status, self::UNASSIGNABLE_STATUSES, true)) {
                throw new \RuntimeException("Booking is '{$booking->status}', not currently assigned to a provider.");
            }

            $previousProviderId = $booking->provider_id;

            $booking->provider_id = null;
            $booking->assigned_worker_id = null;
            $booking->status = 'searching_provider';
            $booking->save();

            // The previous acceptance's dispatch_attempts row (and any
            // other still-notified offers) must not silently let that same
            // provider auto-re-accept as if nothing happened —
            // excludedProviderIdsForBooking() already treats 'accepted' as
            // permanently excluded, so this booking's re-opened offer
            // cycle naturally never goes back to them. Every other
            // 'notified' row is stale from before this reopen and is
            // closed out the same way AcceptBookingAction closes rival
            // offers on a real acceptance.
            DispatchAttempt::where('booking_id', $bookingId)
                ->where('status', 'notified')
                ->update(['status' => 'timeout', 'responded_at' => now()]);

            $booking->statusHistory()->create([
                'status' => 'searching_provider',
                'note' => "Provider #{$previousProviderId} unassigned — {$reason}. Re-opened for provider offers.",
                'changed_at' => now(),
            ]);

            event(new BookingStatusUpdated($booking));

            return ['booking' => $booking->fresh(), 'previous_provider_id' => $previousProviderId];
        });

        $previousProviderId = $booking['previous_provider_id'];
        $booking = $booking['booking'];

        if ($booking->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);

            try {
                $booking->customer->notify(new BookingStatusNotification('scheduled_still_searching', $booking, $channels));
            } catch (\Throwable $e) {
                Log::error("UnassignScheduledProviderAction: failed to notify customer for booking [{$booking->id}]: ".$e->getMessage());
            }
        }

        $previousProvider = \App\Models\Provider::find($previousProviderId);
        if ($previousProvider?->user) {
            $channels = ChannelResolver::resolve(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);

            try {
                $previousProvider->user->notify(new ProviderJobStatusNotification('cancelled', $booking, $channels));
            } catch (\Throwable $e) {
                Log::error("UnassignScheduledProviderAction: failed to notify previous provider for booking [{$booking->id}]: ".$e->getMessage());
            }
        }

        // Re-enter the open provider-offer cycle immediately — same
        // idempotent method the scheduler catch-up calls, so this never
        // duplicates offers to anyone still legitimately holding one.
        $this->scheduledDispatch->sendOpenOffers($booking);

        return $booking;
    }
}
