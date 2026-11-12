<?php

namespace App\Services\Hotels;

use App\Models\Accommodation;
use App\Models\HotelReservation;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Hotels H4 — reviews from verified stays only (owner 2026-11-12: stand against the aggregators; fake reviews are their
 * weak point). Only the guest who booked, only after check-out, once per booking: an overall rating, cleanliness /
 * staff / location / value, and a comment. The hotel replies once (can edit its reply). Ratings feed the city list,
 * the hotel page and Google's markup.
 */
class HotelReviewService
{
    public const SCORES = ['cleanliness', 'staff', 'location', 'value'];

    public function canReview(HotelReservation $r, ?User $customer): bool
    {
        return $customer !== null && $r->customer_id === $customer->id
            && in_array($r->status, ['checked_out', 'completed'], true)
            && ! Review::where('hotel_reservation_id', $r->id)->exists();
    }

    /** @param array<string, int> $scores */
    public function submit(HotelReservation $r, User $customer, int $rating, array $scores, ?string $comment): Review
    {
        return DB::transaction(function () use ($r, $customer, $rating, $scores, $comment) {
            $locked = HotelReservation::lockForUpdate()->findOrFail($r->id);
            if (! $this->canReview($locked, $customer)) {
                throw new \RuntimeException('You can review a stay once, after you check out.');
            }
            if ($rating < 1 || $rating > 5) {
                throw new \RuntimeException('Choose 1 to 5 stars.');
            }
            $clean = [];
            foreach (self::SCORES as $k) {
                if (isset($scores[$k]) && (int) $scores[$k] >= 1 && (int) $scores[$k] <= 5) {
                    $clean[$k] = (int) $scores[$k];
                }
            }

            return Review::create([
                'hotel_reservation_id' => $locked->id,
                'customer_id' => $customer->id,
                'provider_id' => $locked->accommodation->provider_id,
                'rating' => $rating,
                'hotel_scores' => $clean ?: null,
                'comment' => filled($comment) ? mb_substr(trim($comment), 0, 1000) : null,
            ]);
        });
    }

    public function reply(Review $review, Accommodation $hotel, string $reply): Review
    {
        abort_unless($review->hotelReservation?->accommodation_id === $hotel->id, 404);
        $review->forceFill(['provider_reply' => mb_substr(trim($reply), 0, 1000), 'replied_at' => now()])->save();

        return $review;
    }

    /** @return array{avg: float|null, n: int, scores: array<string, float>} */
    public static function summary(Accommodation $hotel): array
    {
        $reviews = Review::query()->whereHas('hotelReservation', fn ($q) => $q->where('accommodation_id', $hotel->id))->get(['rating', 'hotel_scores']);
        $scores = [];
        foreach (self::SCORES as $k) {
            $vals = $reviews->pluck('hotel_scores')->filter()->pluck($k)->filter();
            if ($vals->isNotEmpty()) {
                $scores[$k] = round($vals->avg(), 1);
            }
        }

        return ['avg' => $reviews->isEmpty() ? null : round($reviews->avg('rating'), 1), 'n' => $reviews->count(), 'scores' => $scores];
    }
}
