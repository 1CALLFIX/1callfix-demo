<?php

namespace Tests\Feature\Reviews;

use App\Models\Booking;
use App\Models\Provider;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase D — rating manipulation, over HTTP.
 *
 * ReviewTest already covers the happy path and the service-level rules. This
 * suite is the adversarial half: every way of attaching a rating to a booking
 * the caller has no claim on, or of rating something that has not happened
 * yet, driven through `POST /api/bookings/{id}/review` and
 * `POST /api/reviews/{id}/reply` rather than through ReviewService directly —
 * an authorization rule that only holds when called from PHP is not a rule.
 *
 * The rules being probed are the ones ReviewService already enforces
 * (ownership, completed-only, one per booking, 1..5, provider-owns-the-review
 * for replies). Nothing new was added to make these pass; they are here
 * because "the service checks it" and "the endpoint enforces it" are
 * different claims.
 */
class ReviewAbuseTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function completedBooking(): Booking
    {
        $scenario = $this->makeAssignedBookingScenario();
        $scenario['booking']->update(['status' => 'completed', 'completed_at' => now()]);

        return $scenario['booking']->fresh(['provider.user', 'customer']);
    }

    // ==================== Rating before it is earned ====================

    public function test_a_rating_cannot_be_left_before_the_booking_is_completed(): void
    {
        foreach (['pending', 'searching_provider', 'assigned', 'in_progress'] as $status) {
            $scenario = $this->makeAssignedBookingScenario();
            $scenario['booking']->update(['status' => $status]);

            $this->actingAs($scenario['customer'], 'sanctum')
                ->postJson("/api/bookings/{$scenario['booking']->id}/review", ['rating' => 5])
                ->assertStatus(422);
        }

        $this->assertSame(0, Review::count());
    }

    public function test_a_rating_cannot_be_left_on_a_cancelled_booking(): void
    {
        $scenario = $this->makeAssignedBookingScenario();
        $scenario['booking']->update(['status' => 'cancelled']);

        $this->actingAs($scenario['customer'], 'sanctum')
            ->postJson("/api/bookings/{$scenario['booking']->id}/review", ['rating' => 1])
            ->assertStatus(422);

        $this->assertSame(0, Review::count());
    }

    public function test_an_unauthenticated_caller_cannot_leave_a_rating(): void
    {
        $booking = $this->completedBooking();

        $this->postJson("/api/bookings/{$booking->id}/review", ['rating' => 5])->assertStatus(401);

        $this->assertSame(0, Review::count());
    }

    // ==================== IDOR ====================

    public function test_a_customer_cannot_rate_another_customers_completed_booking(): void
    {
        $booking = $this->completedBooking();
        $stranger = $this->makeCustomer();

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/review", ['rating' => 1, 'comment' => 'Sabotage'])
            ->assertStatus(422);

        $this->assertSame(0, Review::count());
        $this->assertEquals(0, Provider::findOrFail($booking->provider_id)->fresh()->rating_avg,
            "A stranger's rating must not have moved the provider's average.");
    }

    /**
     * The rating's target is read from the booking, never from the payload —
     * so a customer cannot aim a genuine review of their own booking at a
     * different provider.
     */
    public function test_the_provider_rated_is_taken_from_the_booking_not_from_the_request_body(): void
    {
        $booking = $this->completedBooking();
        $otherProvider = $this->makeProviderIn($booking->franchise, $booking->zone);
        $otherCustomer = $this->makeCustomer();

        $this->actingAs($booking->customer, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/review", [
                'rating' => 1,
                'provider_id' => $otherProvider->id,
                'customer_id' => $otherCustomer->id,
                'booking_id' => 999999,
            ])
            ->assertOk();

        $review = Review::firstOrFail();
        $this->assertSame($booking->provider_id, $review->provider_id);
        $this->assertSame($booking->customer_id, $review->customer_id);
        $this->assertSame($booking->id, $review->booking_id);
        $this->assertEquals(0, $otherProvider->fresh()->rating_avg);
    }

    public function test_rating_a_booking_that_does_not_exist_is_a_404_not_a_new_review(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/bookings/999999/review', ['rating' => 5])
            ->assertStatus(404);

        $this->assertSame(0, Review::count());
    }

    // ==================== Ballot stuffing ====================

    public function test_the_same_booking_cannot_be_rated_twice(): void
    {
        $booking = $this->completedBooking();

        $this->actingAs($booking->customer, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/review", ['rating' => 5])->assertOk();

        $this->actingAs($booking->customer, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/review", ['rating' => 5])->assertStatus(422);

        $this->assertSame(1, Review::count());
        $this->assertEquals(5, Provider::findOrFail($booking->provider_id)->fresh()->rating_avg);
    }

    public function test_a_rating_outside_one_to_five_is_rejected_by_validation(): void
    {
        $booking = $this->completedBooking();

        foreach ([0, 6, -1, 100] as $rating) {
            $this->actingAs($booking->customer, 'sanctum')
                ->postJson("/api/bookings/{$booking->id}/review", ['rating' => $rating])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['rating']);
        }

        $this->assertSame(0, Review::count());
    }

    // ==================== Provider replies ====================

    public function test_a_provider_cannot_reply_to_a_review_of_another_providers_booking(): void
    {
        $booking = $this->completedBooking();
        $this->actingAs($booking->customer, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/review", ['rating' => 2])->assertOk();
        $review = Review::firstOrFail();

        $intruder = $this->makeProviderIn($booking->franchise, $booking->zone);

        $this->actingAs($intruder->user, 'sanctum')
            ->postJson("/api/reviews/{$review->id}/reply", ['reply' => 'Not my job, but here I am.'])
            ->assertStatus(403);

        $this->assertNull($review->fresh()->provider_reply);
    }

    public function test_a_customer_account_cannot_reply_to_a_review(): void
    {
        $booking = $this->completedBooking();
        $this->actingAs($booking->customer, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/review", ['rating' => 2])->assertOk();
        $review = Review::firstOrFail();

        $this->actingAs($booking->customer, 'sanctum')
            ->postJson("/api/reviews/{$review->id}/reply", ['reply' => 'Replying to myself.'])
            ->assertStatus(403);

        $this->assertNull($review->fresh()->provider_reply);
    }

    // ==================== The control ====================

    public function test_the_bookings_own_customer_can_rate_it_once_it_is_completed(): void
    {
        $booking = $this->completedBooking();

        $this->actingAs($booking->customer, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/review", ['rating' => 4, 'comment' => 'Good job'])
            ->assertOk()
            ->assertJsonPath('review.rating', 4);

        $this->assertSame(1, Review::count());
        $this->assertEquals(4, Provider::findOrFail($booking->provider_id)->fresh()->rating_avg);
    }
}
