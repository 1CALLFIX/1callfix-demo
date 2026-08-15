<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13 (Glover/6amMart parity audit) finding: `reviews` has existed
 * since Phase 1 — real schema, real `Review` model, a real `ReviewObserver`
 * that recomputes `providers.rating_avg` — with ZERO write path anywhere
 * in the codebase (confirmed by a full-codebase grep for `Review::create`,
 * independently corroborated by the parity audit against 6amMart, which
 * has a live, wired review system 1CallFix's own dormant schema was built
 * to match but never finished). `providers.rating_avg` has therefore been
 * permanently 0 since the column was added — a real ranking criterion
 * (Settings > Priority/Ranking) silently wired to dead data.
 */
class ReviewService
{
    /** One review per booking (real DB unique constraint, not just this check — closes the same TOCTOU window every other idempotency guard in this codebase closes). Only the booking's own customer may review, and only once it's completed — a booking can't be reviewed before the work happened. */
    public function submit(Booking $booking, User $customer, int $rating, ?string $comment = null): Review
    {
        if ($booking->customer_id !== $customer->id) {
            throw new \RuntimeException('This booking does not belong to you.');
        }

        if ($booking->status !== 'completed') {
            throw new \RuntimeException('You can only review a booking after it is completed.');
        }

        if (! $booking->provider_id) {
            throw new \RuntimeException('This booking has no assigned provider to review.');
        }

        if ($rating < 1 || $rating > 5) {
            throw new \InvalidArgumentException('Rating must be between 1 and 5.');
        }

        if (Review::where('booking_id', $booking->id)->exists()) {
            throw new \RuntimeException('This booking has already been reviewed.');
        }

        try {
            return DB::transaction(fn () => Review::create([
                'booking_id' => $booking->id,
                'customer_id' => $customer->id,
                'provider_id' => $booking->provider_id,
                'rating' => $rating,
                'comment' => $comment,
            ]));
        } catch (\Illuminate\Database\QueryException $e) {
            // The unique constraint is the real guard under concurrency —
            // the exists() check above is the fast path, this catches a
            // genuine simultaneous double-submit.
            throw new \RuntimeException('This booking has already been reviewed.');
        }
    }

    /** A provider replying to their own review — the same "one round-trip, no thread" shape reviews.provider_reply's own column implies (a single nullable text field, not a second table). */
    public function reply(int $reviewId, int $providerId, string $reply): Review
    {
        $review = Review::find($reviewId);

        if (! $review) {
            throw new ModelNotFoundException("Review [{$reviewId}] not found.");
        }

        if ($review->provider_id !== $providerId) {
            throw new \RuntimeException('This review is not for one of your bookings.');
        }

        $review->update(['provider_reply' => $reply]);

        return $review;
    }
}
