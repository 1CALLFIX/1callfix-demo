<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Orders\Index as OrdersIndex;
use App\Livewire\Customer\Orders\Show as OrderShow;
use App\Models\Booking;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase E6 — order history + one order's live status, OTP codes, cancel and
 * review. Every action delegates to an existing engine
 * (AdminCancelBookingAction, ReviewService); the OTP codes are shown, never
 * entered. IDOR: another customer's booking is a 404, not a 403.
 */
class CustomerOrderTrackingTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private function bookingFor(\App\Models\User $customer, array $overrides = []): Booking
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $category = $this->makeCategory(['module' => 'service']);
        $service = $this->makeService($category);
        $address = $this->makeAddress($customer, $franchise, $zone);
        $provider = $this->makeProviderIn($franchise, $zone);

        return Booking::create(array_merge([
            'code' => 'E6-'.fake()->unique()->numerify('########'),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'service_id' => $service->id, 'address_id' => $address->id,
            'provider_id' => $provider->id,
            'status' => 'assigned', 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'cash',
            'start_otp' => '1234', 'completion_otp' => '5678',
        ], $overrides));
    }

    // ------------------------------------------------------------- history

    public function test_orders_index_shows_only_the_authed_customers_bookings(): void
    {
        $me = $this->makeCustomer();
        $mine = $this->bookingFor($me);
        $stranger = $this->makeCustomer();
        $theirs = $this->bookingFor($stranger);

        Livewire::actingAs($me)->test(OrdersIndex::class)
            ->assertSee($mine->code)
            ->assertDontSee($theirs->code);
    }

    public function test_orders_index_filters_by_bucket(): void
    {
        $me = $this->makeCustomer();
        $active = $this->bookingFor($me, ['status' => 'searching_provider']);
        $done = $this->bookingFor($me, ['status' => 'completed', 'completed_at' => now(), 'price_final' => 500]);

        Livewire::actingAs($me)->test(OrdersIndex::class)
            ->set('filter', 'completed')
            ->assertSee($done->code)
            ->assertDontSee($active->code);
    }

    // ------------------------------------------------------------- detail / IDOR

    public function test_a_customer_cannot_open_another_customers_order(): void
    {
        $me = $this->makeCustomer();
        $theirs = $this->bookingFor($this->makeCustomer());

        $this->actingAs($me)->get(route('customer.orders.show', $theirs))->assertNotFound();
    }

    public function test_the_owner_sees_the_start_and_completion_otp_codes_while_the_job_is_live(): void
    {
        $me = $this->makeCustomer();
        $booking = $this->bookingFor($me, ['status' => 'assigned']);

        Livewire::actingAs($me)->test(OrderShow::class, ['booking' => $booking])
            ->assertSee('1234')
            ->assertSee('5678')
            ->assertSee('Read these to your professional');
    }

    public function test_the_start_otp_is_hidden_once_the_job_is_in_progress(): void
    {
        $me = $this->makeCustomer();
        // E5 NULLs start_otp on a verified start; mirror that state here.
        $booking = $this->bookingFor($me, ['status' => 'in_progress', 'start_otp' => null]);

        Livewire::actingAs($me)->test(OrderShow::class, ['booking' => $booking])
            ->assertDontSee('When they arrive')
            ->assertSee('5678'); // completion code still needed
    }

    // ------------------------------------------------------------- cancel

    public function test_the_customer_can_cancel_through_the_existing_cancel_action(): void
    {
        $me = $this->makeCustomer();
        $booking = $this->bookingFor($me, ['status' => 'searching_provider']);

        Livewire::actingAs($me)->test(OrderShow::class, ['booking' => $booking])
            ->call('cancel')
            ->assertSee('cancelled');

        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertDatabaseHas('booking_status_history', ['booking_id' => $booking->id, 'status' => 'cancelled']);
    }

    public function test_a_completed_booking_cannot_be_cancelled_from_the_web(): void
    {
        $me = $this->makeCustomer();
        $booking = $this->bookingFor($me, ['status' => 'completed', 'completed_at' => now(), 'price_final' => 500]);

        Livewire::actingAs($me)->test(OrderShow::class, ['booking' => $booking])
            ->call('cancel')
            ->assertSee('already completed');

        $this->assertSame('completed', $booking->fresh()->status);
    }

    // ------------------------------------------------------------- review

    public function test_a_review_can_only_be_left_on_a_completed_booking_and_only_once(): void
    {
        $me = $this->makeCustomer();
        $booking = $this->bookingFor($me, ['status' => 'completed', 'completed_at' => now(), 'price_final' => 500]);

        Livewire::actingAs($me)->test(OrderShow::class, ['booking' => $booking])
            ->set('rating', 4)
            ->set('comment', 'Great work')
            ->call('submitReview')
            ->assertSee('Thanks for the review');

        $this->assertDatabaseHas('reviews', ['booking_id' => $booking->id, 'customer_id' => $me->id, 'rating' => 4]);

        // Second attempt is refused by ReviewService's own one-per-booking rule.
        Livewire::actingAs($me)->test(OrderShow::class, ['booking' => $booking->fresh()])
            ->set('rating', 1)
            ->call('submitReview')
            ->assertSee('already been reviewed');

        $this->assertSame(1, Review::where('booking_id', $booking->id)->count());
    }

    public function test_a_pre_completion_booking_shows_no_review_form(): void
    {
        $me = $this->makeCustomer();
        $booking = $this->bookingFor($me, ['status' => 'assigned']);

        Livewire::actingAs($me)->test(OrderShow::class, ['booking' => $booking])
            ->assertDontSee('Rate your experience');
    }

    // ------------------------------------------------------------- rebook

    public function test_the_order_page_links_back_to_a_fresh_wizard_for_the_same_service(): void
    {
        $me = $this->makeCustomer();
        $booking = $this->bookingFor($me, ['status' => 'completed', 'completed_at' => now(), 'price_final' => 500]);

        Livewire::actingAs($me)->test(OrderShow::class, ['booking' => $booking])
            ->assertSee(route('customer.book', $booking->service), false)
            ->assertSee('Book this again');
    }
}
