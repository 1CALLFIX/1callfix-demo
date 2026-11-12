<?php

namespace Tests\Feature\Hotel;

use App\Livewire\Customer\Hotels\Booking;
use App\Livewire\Hotel\Dashboard;
use App\Models\Franchise;
use App\Models\HotelReservation;
use App\Models\Review;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Services\Hotels\HotelReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Support\HotelFixtureHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * Hotels H4 (owner 2026-11-12: stand against the aggregators, fastest booking in a few taps): reviews only from
 * verified stays with the hotel's reply, and a one-tap "Book now" on every free hotel in the city list.
 */
class HotelReviewsAndQuickBookTest extends TestCase
{
    use RefreshDatabase;
    use LiveCity;
    use HotelFixtureHelpers;

    private $hotel;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $city = $this->liveCity('Nellore');
        $franchise = Franchise::where('city_id', $city->id)->firstOrFail();
        $zone = Zone::where('franchise_id', $franchise->id)->firstOrFail();
        $this->activateHotelFor($franchise);
        Setting::set('hotel.gst_slabs', '* : 0');
        $this->hotel = $this->makeAccommodation($franchise, $zone, null, ['approval_status' => 'approved', 'name' => 'Review Inn']);
        $room = $this->makeRoomType($this->hotel, ['total_inventory' => 2]);
        $this->makeRatePlan($room, ['nightly_rate' => 1200]);
        $this->customer = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Asha Rao', 'phone' => '9'.fake()->numerify('#########'), 'role' => 'customer', 'status' => 'active']);
    }

    private function stay(string $status): HotelReservation
    {
        return HotelReservation::create([
            'franchise_id' => $this->hotel->franchise_id, 'zone_id' => $this->hotel->zone_id, 'customer_id' => $this->customer->id,
            'accommodation_id' => $this->hotel->id, 'check_in_date' => now()->subDays(3)->toDateString(), 'check_out_date' => now()->subDay()->toDateString(),
            'number_of_nights' => 2, 'number_of_rooms' => 1, 'number_of_adults' => 2, 'status' => $status,
            'price_quoted' => 2400, 'payment_status' => 'paid', 'payment_method' => 'online',
        ]);
    }

    public function test_only_a_checked_out_guest_reviews_once_and_the_hotel_page_shows_it(): void
    {
        $confirmed = $this->stay('confirmed');
        $this->assertFalse(app(HotelReviewService::class)->canReview($confirmed, $this->customer), 'Not before check-out.');

        $stay = $this->stay('checked_out');
        Livewire::actingAs($this->customer)->test(Booking::class, ['code' => $stay->code])
            ->set('rating', 4)->set('scores', ['cleanliness' => 5, 'staff' => 4])->set('reviewComment', 'Clean rooms, friendly desk.')
            ->call('submitReview')->assertSet('error', '');
        $this->assertSame(1, Review::where('hotel_reservation_id', $stay->id)->count());

        $this->expectException(\RuntimeException::class);
        app(HotelReviewService::class)->submit($stay, $this->customer, 5, [], 'again');
    }

    public function test_hotel_replies_and_guests_see_rating_scores_and_reply(): void
    {
        $stay = $this->stay('completed');
        $review = app(HotelReviewService::class)->submit($stay, $this->customer, 5, ['location' => 4], 'Great location.');

        Livewire::actingAs($this->hotel->provider->user)->test(Dashboard::class)
            ->set('replies.'.$review->id, 'Thank you, Asha!')->call('reply', $review->id)->assertHasNoErrors();
        $this->assertSame('Thank you, Asha!', $review->fresh()->provider_reply);

        $this->get('/nellore/hotels/'.$this->hotel->slug)->assertOk()
            ->assertSee('★ 5')->assertSee('Great location.')->assertSee('Thank you, Asha!')->assertSee('Location')
            ->assertSee('"aggregateRating"', false);
    }

    public function test_another_hotel_cannot_reply_to_this_hotels_review(): void
    {
        $review = app(HotelReviewService::class)->submit($this->stay('completed'), $this->customer, 3, [], null);
        $other = $this->makeAccommodation($this->hotel->franchise, $this->hotel->zone, null, ['approval_status' => 'approved']);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        app(HotelReviewService::class)->reply($review, $other, 'Not mine');
    }

    public function test_city_list_has_one_tap_book_now_to_the_booking_page(): void
    {
        $in = now()->addDays(5)->toDateString();
        $out = Carbon::parse($in)->addDay()->toDateString();

        $this->get("/nellore/hotels?in={$in}&out={$out}")->assertOk()
            ->assertSee('data-testid="hotel-quick-book"', false)->assertSee('Book now')
            ->assertSee('/hotels/book?hotel='.$this->hotel->id, false);
    }
}
