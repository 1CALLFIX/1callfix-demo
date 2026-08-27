<?php

namespace App\Services\Customer;

use App\Models\Review;
use Illuminate\Support\Collection;

/**
 * Average star rating and review count per SERVICE, for the customer catalog
 * (Phase C).
 *
 * ── Why this has to be derived ────────────────────────────────────────────
 * `reviews` has no `service_id`. A review belongs to a booking (or, for the
 * other verticals, to one of the reservation/order columns) and to the
 * PROVIDER who did the work — the rating a customer leaves is a rating of
 * that job, and the aggregate the schema is built for is a provider's
 * reputation, not a catalog listing's. `services` likewise carries no
 * rating/review_count column (confirmed against its migration and every
 * later ALTER before writing this).
 *
 * So the only honest per-service rating available is: reviews attached to
 * bookings of that service. That is what this class computes — a read-only
 * aggregate over real rows, with nothing written, cached or backfilled, and
 * no new "service rating" column invented that would immediately start
 * drifting from the reviews it was derived from.
 *
 * Reviews reached through the other verticals' columns
 * (property_reservation_id, marketplace_order_id, ...) are excluded by
 * construction: the join is on `bookings`, which only the Service vertical
 * writes.
 *
 * ── Why it is batched ─────────────────────────────────────────────────────
 * A homepage renders ~20 service cards. Asking each card for its own rating
 * is 20 queries plus 20 more for the counts. forServices() answers the whole
 * grid in ONE grouped query, and every caller in the customer app is
 * expected to use it rather than calling forService() in a loop.
 *
 * ── What callers must do with "no reviews" ────────────────────────────────
 * A service nobody has reviewed is simply absent from the returned map. It
 * is NOT reported as 0.0 stars, and callers must hide the rating element
 * entirely rather than render an empty five-star row — an unrated service
 * and a badly-rated one must never look the same.
 */
class ServiceRatingSummary
{
    /**
     * @param  iterable<int>  $serviceIds
     * @return Collection<int, array{average: float, count: int}> keyed by service id; services with no reviews are absent
     */
    public function forServices(iterable $serviceIds): Collection
    {
        $ids = collect($serviceIds)->map(fn ($id) => (int) $id)->unique()->filter()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Review::query()
            ->join('bookings', 'reviews.booking_id', '=', 'bookings.id')
            ->whereIn('bookings.service_id', $ids)
            // Soft-deleted bookings are excluded by hand: this is a manual
            // join, so Booking's SoftDeletes global scope never runs on it.
            ->whereNull('bookings.deleted_at')
            ->groupBy('bookings.service_id')
            ->selectRaw('bookings.service_id as service_id, avg(reviews.rating) as average_rating, count(reviews.id) as review_count')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->service_id => [
                    // One decimal is what the card renders ("4.6"); rounding
                    // here rather than in Blade keeps every screen showing
                    // the identical number.
                    'average' => round((float) $row->average_rating, 1),
                    'count' => (int) $row->review_count,
                ],
            ]);
    }

    /** @return array{average: float, count: int}|null null when the service has no reviews at all */
    public function forService(int $serviceId): ?array
    {
        return $this->forServices([$serviceId])->get($serviceId);
    }

    /**
     * The most recent reviews for one service, for the detail page.
     * Eager-loads the reviewer so the list can show a name without an N+1,
     * and only returns reviews that actually carry a written comment — a
     * bare star with no words is real data, but it is already represented in
     * the average and adds nothing as a list entry.
     *
     * @return Collection<int, Review>
     */
    public function recentFor(int $serviceId, int $limit = 5): Collection
    {
        return Review::query()
            ->whereHas('booking', fn ($b) => $b->where('service_id', $serviceId))
            ->whereNotNull('comment')
            ->where('comment', '!=', '')
            ->with('customer:id,name')
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }
}
