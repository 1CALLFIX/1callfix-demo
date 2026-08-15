<?php

namespace Tests\Feature\Reviews;

use App\Livewire\Providers\Show as ProvidersShow;
use App\Models\Booking;
use App\Models\Review;
use App\Services\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase 13 (Glover/6amMart parity audit) finding: `reviews` has existed
 * since Phase 1 — real schema, real model, a real `ReviewObserver` that
 * recomputes `providers.rating_avg` — with ZERO write path anywhere
 * (confirmed by a full-codebase grep, independently corroborated by the
 * parity audit against 6amMart's live review system). This suite covers
 * the new write path: POST /api/bookings/{id}/review, POST
 * /api/reviews/{id}/reply, and the admin Providers\Show visibility that
 * was already there (rating_avg) but had never had real data behind it.
 */
class ReviewTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    private function completedBooking(): Booking
    {
        $scenario = $this->makeAssignedBookingScenario();
        $booking = $scenario['booking'];
        $booking->update(['status' => 'completed', 'completed_at' => now()]);

        return $booking->fresh(['provider.user', 'customer']);
    }

    public function test_customer_can_review_a_completed_booking(): void
    {
        $booking = $this->completedBooking();

        $this->actingAs($booking->customer, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/review", ['rating' => 5, 'comment' => 'Great work!'])
            ->assertOk()
            ->assertJsonPath('review.rating', 5);

        $this->assertDatabaseHas('reviews', [
            'booking_id' => $booking->id, 'customer_id' => $booking->customer_id,
            'provider_id' => $booking->provider_id, 'rating' => 5, 'comment' => 'Great work!',
        ]);
    }

    public function test_review_recomputes_provider_rating_avg(): void
    {
        $booking = $this->completedBooking();

        app(ReviewService::class)->submit($booking, $booking->customer, 4);

        $this->assertSame(4.0, (float) $booking->provider->fresh()->rating_avg);
    }

    public function test_review_is_rejected_for_someone_elses_booking(): void
    {
        $booking = $this->completedBooking();
        $otherCustomer = $this->makeCustomer();

        $this->actingAs($otherCustomer, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/review", ['rating' => 5])
            ->assertStatus(422);

        $this->assertDatabaseMissing('reviews', ['booking_id' => $booking->id]);
    }

    public function test_review_is_rejected_for_a_non_completed_booking(): void
    {
        $scenario = $this->makeAssignedBookingScenario();

        $this->actingAs($scenario['customer'], 'sanctum')
            ->postJson("/api/bookings/{$scenario['booking']->id}/review", ['rating' => 5])
            ->assertStatus(422);
    }

    public function test_duplicate_review_is_rejected(): void
    {
        $booking = $this->completedBooking();
        app(ReviewService::class)->submit($booking, $booking->customer, 5);

        $this->actingAs($booking->customer, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/review", ['rating' => 3])
            ->assertStatus(422);

        $this->assertSame(1, Review::where('booking_id', $booking->id)->count());
    }

    public function test_rating_out_of_range_is_rejected(): void
    {
        $booking = $this->completedBooking();

        $this->actingAs($booking->customer, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/review", ['rating' => 6])
            ->assertStatus(422);
    }

    public function test_provider_can_reply_to_a_review_on_their_own_booking(): void
    {
        $booking = $this->completedBooking();
        $review = app(ReviewService::class)->submit($booking, $booking->customer, 5, 'Nice.');

        $this->actingAs($booking->provider->user, 'sanctum')
            ->postJson("/api/reviews/{$review->id}/reply", ['reply' => 'Thank you!'])
            ->assertOk()
            ->assertJsonPath('review.provider_reply', 'Thank you!');

        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'provider_reply' => 'Thank you!']);
    }

    public function test_provider_cannot_reply_to_someone_elses_review(): void
    {
        $booking = $this->completedBooking();
        $review = app(ReviewService::class)->submit($booking, $booking->customer, 5);

        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $otherProvider = $this->makeProviderIn($franchise, $zone);

        $this->actingAs($otherProvider->user, 'sanctum')
            ->postJson("/api/reviews/{$review->id}/reply", ['reply' => 'Not mine.'])
            ->assertStatus(403);

        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'provider_reply' => null]);
    }

    public function test_admin_provider_screen_shows_the_review(): void
    {
        $booking = $this->completedBooking();
        app(ReviewService::class)->submit($booking, $booking->customer, 5, 'Excellent service, five stars!');

        $actor = $this->makeUserWithPermission('providers.view', 'global');

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $booking->provider_id])
            ->assertOk()
            ->assertSee('Excellent service, five stars!')
            ->assertSee('5.00 / 5');
    }
}
