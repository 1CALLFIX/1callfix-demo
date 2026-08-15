<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\ReviewService;
use Illuminate\Http\Request;

/**
 * Phase 13 (Glover/6amMart parity audit) — the real write path for the
 * `reviews` table, dormant since Phase 1 (see ReviewService's docblock).
 * Same convention as TipController/ChatController: booking-scoped,
 * unauthenticated actions rejected by the service's own ownership checks
 * rather than trusted route params.
 */
class ReviewController extends Controller
{
    /** POST /api/bookings/{bookingId}/review */
    public function store(Request $request, int $bookingId, ReviewService $reviews)
    {
        $booking = Booking::find($bookingId);
        if (! $booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $review = $reviews->submit($booking, $request->user(), (int) $validated['rating'], $validated['comment'] ?? null);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Review submitted.',
            'review' => ['id' => $review->id, 'rating' => $review->rating, 'comment' => $review->comment],
        ]);
    }

    /** POST /api/reviews/{reviewId}/reply — the provider replying to a review on one of their own bookings. */
    public function reply(Request $request, int $reviewId, ReviewService $reviews)
    {
        $provider = $request->user()->providerProfile;
        if (! $provider) {
            return response()->json(['message' => 'Only provider accounts can reply to reviews.'], 403);
        }

        $validated = $request->validate([
            'reply' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $review = $reviews->reply($reviewId, $provider->id, $validated['reply']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Review not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['message' => 'Reply saved.', 'review' => ['id' => $review->id, 'provider_reply' => $review->provider_reply]]);
    }
}
