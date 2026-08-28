<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Provider;
use Carbon\CarbonInterface;

/**
 * Phase E4 (Provider Availability & Bundle Dispatch Consolidation) — the
 * time-slot counterpart to DispatchService's coarse "is this provider tied
 * up on another active booking" filter (DispatchService::findCandidates()'s
 * $busyProviderIds).
 *
 * Until E4, a Provider could only ever hold one job at a time, so a plain
 * "has any active booking" check was enough and `scheduled_at` never entered
 * into availability at all. Bundle consolidation deliberately lets ONE
 * provider hold several bundle-sibling jobs whose scheduled windows don't
 * overlap — so we now need a real half-open `[start, end)` interval check,
 * the same overlap convention PropertyAvailabilityService /
 * RentalAvailabilityService already use for their reservation ranges.
 *
 * A Service booking occupies:
 *
 *     start = booking.scheduled_at
 *     end   = booking.scheduled_at + services.duration_estimate_mins
 *
 * and two bookings conflict iff `existing.start < requested.end` AND
 * `requested.start < existing.end` (touching end-to-start is NOT a conflict).
 *
 * This is the READ-ONLY primitive (mirrors
 * RentalAvailabilityService::isAvailable()): a "looks free" answer here can
 * always be raced by a real acceptance between this call and the assignment.
 * The authoritative, race-safe recheck happens inside
 * AcceptBookingAction's assignment transaction, with the provider row locked
 * FOR UPDATE (see that class).
 *
 * Deliberately NOT modelled here (out of E4 scope): working hours, shifts,
 * breaks, travel-time buffers, geofencing beyond the existing dispatch
 * radius.
 */
class ProviderAvailabilityService
{
    /**
     * Booking statuses that represent provider-confirmed occupancy — exactly
     * DispatchService::findCandidates()'s own $busyProviderIds set. A booking
     * that is still `pending` / `searching_provider` has no committed
     * provider and must NOT reserve a provider's time; `completed` /
     * `cancelled` / `disputed` are terminal and free the slot for the future.
     */
    public const BLOCKING_STATUSES = ['assigned', 'provider_en_route', 'in_progress', 'on_hold'];

    /**
     * Is $provider free for the whole half-open interval
     * [$startTime, $startTime + $durationMinutes)?
     *
     * @param  int|null  $excludeBookingId  ignore this booking when checking
     *         (e.g. re-checking a booking's own row, or checking a candidate
     *         sibling that has no confirmed provider yet anyway)
     */
    public function isAvailableAt(
        Provider $provider,
        CarbonInterface $startTime,
        int $durationMinutes,
        ?int $excludeBookingId = null
    ): bool {
        $requestedStart = $startTime->copy();
        $requestedEnd = $startTime->copy()->addMinutes(max(0, $durationMinutes));

        $blocking = Booking::query()
            ->with('service:id,duration_estimate_mins')
            ->where('provider_id', $provider->id)
            ->whereIn('status', self::BLOCKING_STATUSES)
            ->whereNotNull('scheduled_at')
            ->when($excludeBookingId, fn ($q) => $q->where('id', '!=', $excludeBookingId))
            ->get();

        foreach ($blocking as $booking) {
            $existingStart = $booking->scheduled_at->copy();
            $existingEnd = $booking->scheduled_at->copy()
                ->addMinutes((int) ($booking->service->duration_estimate_mins ?? 0));

            // Half-open overlap: [a, b) and [c, d) overlap iff a < d && c < b.
            if ($existingStart < $requestedEnd && $requestedStart < $existingEnd) {
                return false;
            }
        }

        return true;
    }
}
