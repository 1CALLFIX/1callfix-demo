<?php

namespace App\Observers;

use App\Models\Review;

/**
 * providers.rating_avg existed since Phase 1 but nothing in this codebase
 * ever recomputed it from the reviews table (confirmed by audit — zero
 * Review::create() call sites anywhere). Ranking by "rating" would
 * otherwise be wiring a criterion to permanently-zero data, which the
 * Priority/Ranking rule explicitly says not to do. This closes that gap
 * the same way BookingObserver/FranchiseObserver/ZoneObserver already
 * keep their own rollups current — registered in AppServiceProvider::boot().
 */
class ReviewObserver
{
    public function saved(Review $review): void
    {
        $this->recalculate($review);
    }

    public function deleted(Review $review): void
    {
        $this->recalculate($review);
    }

    private function recalculate(Review $review): void
    {
        $provider = $review->provider;

        if (! $provider) {
            return;
        }

        $provider->rating_avg = round((float) $provider->reviews()->avg('rating'), 2);
        $provider->save();
    }
}
