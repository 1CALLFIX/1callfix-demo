<?php

namespace Tests\Feature\Hotel;

use App\Livewire\Customer\Hotels\Booking as BookingPage;
use App\Livewire\Customer\Hotels\Home;
use App\Livewire\Customer\Hotels\Show;
use App\Livewire\Hotel\Rooms;
use App\Models\Franchise;
use App\Models\HotelReservation;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Services\Hotels\HotelBookingService;
use App\Services\Hotels\HotelStayService;
use App\Services\WalletService;
use App\Support\HotelPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Support\HotelFixtureHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * Hotels step 3 (owner 2026-11-12, "stand against the aggregators"): a cheaper non-refundable price, "Change dates"
 * (pay the new stay; the old one is cancelled free once the new one is confirmed), and search by area / landmark,
 * near me (distance) and a map.
 */
class HotelNonRefundableDatesSearchTest extends TestCase
{
    use RefreshDatabase;
    use LiveCity;
    use HotelFixtureHelpers;

    private Franchise $franchise;

    private Zone $zone;

    private $hotel;

    private $room;

    private $plan;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $city = $this->liveCity('Nellore');
        $this->franchise = Franchise::where('city_id', $city->id)->firstOrFail();
        $this->zone = Zone::where('franchise_id', $this->franchise->id)->firstOrFail();
        $this->activateHotelFor($this->franchise);
        Setting::set('hotel.gst_slabs', "1000 : 0\n7500 : 5\n* : 18");
        Setting::set('payment.wallet_enabled', '1');
        $this->hotel = $this->makeAccommodation($this->franchise, $this->zone, null, [
            'approval_status' => 'approved', 'name' => 'Sea View Lodge', 'address_line' => 'Near RTC bus stand, Nellore',
            'lat' => 14.4426, 'lng' => 79.9865,
        ]);
        $this->room = $this->makeRoomType($this->hotel, ['total_inventory' => 3, 'max_adults' => 2, 'non_refundable_percent' => 10]);
        $this->plan = $this->makeRatePlan($this->room, ['nightly_rate' => 2000]);
        $this->customer = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Asha', 'phone' => '9'.fake()->numerify('#########'), 'role' => 'customer', 'status' => 'active']);
        app(WalletService::class)->credit($this->customer, 20000, reason: 'top-up', ref: 'test-topup-'.Str::random(6));
    }

    private function dates(int $from = 10): array
    {
        $in = now()->addDays($from)->next(Carbon::TUESDAY)->toDateString();

        return [$in, Carbon::parse($in)->addDays(2)->toDateString()];
    }

    private function book(array $options = [], int $from = 10): HotelReservation
    {
        [$in, $out] = $this->dates($from);

        return app(HotelBookingService::class)->book($this->customer, $this->hotel, $this->room, $this->plan, $in, $out, 1, 2, ['guest_name' => 'Asha'], 'wallet', null, 0, $options);
    }

    // ───────────────────────── non-refundable ─────────────────────────

    public function test_the_non_refundable_price_is_cheaper_and_keeps_the_whole_amount(): void
    {
        $r = $this->book(['non_refundable' => true]);

        // ₹2,000 − 10% = ₹1,800 a night (0% GST band below ₹1,000? no: 5% band) → 2 × ₹1,800 = ₹3,600 + 5% = ₹3,780.
        $this->assertTrue($r->non_refundable);
        $this->assertEquals(3600.0, (float) $r->price_quoted);
        $this->assertEquals(3780.0, $r->amountDue());
        $this->assertSame('confirmed', $r->status);

        // Even inside the free-cancellation time, nothing comes back.
        [$charge] = app(HotelStayService::class)->chargeFor($r);
        $this->assertEquals(3780.0, $charge);
        app(HotelStayService::class)->customerCancel($r->fresh());
        $this->assertEquals(20000 - 3780, app(WalletService::class)->balance($this->customer->fresh()));
    }

    public function test_no_non_refundable_price_when_the_room_or_super_admin_does_not_offer_it(): void
    {
        Setting::set('hotel.non_refundable_allowed', '0');
        $this->assertNull(HotelPricing::nonRefundablePercent($this->room->fresh()));

        $this->expectExceptionMessage('This room has no non-refundable price.');
        $this->book(['non_refundable' => true]);
    }

    public function test_the_hotel_page_shows_both_prices(): void
    {
        [$in, $out] = $this->dates();
        Livewire::withQueryParams(['in' => $in, 'out' => $out])
            ->test(Show::class, ['city' => 'nellore', 'hotelSlug' => $this->hotel->slug])
            ->assertSee('₹2,100')
            ->assertSee('Non-refundable · 10% cheaper')
            ->assertSee('₹1,890')
            ->assertSee('nr=1', false);
    }

    public function test_the_hotel_sets_its_non_refundable_discount_in_the_portal(): void
    {
        $owner = $this->hotel->provider->user;
        Livewire::actingAs($owner)->test(Rooms::class)
            ->call('edit', $this->room->id)
            ->assertSet('nonRefundablePercent', '10')
            ->set('nonRefundablePercent', '15')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertEquals(15.0, (float) $this->room->fresh()->non_refundable_percent);

        Livewire::actingAs($owner)->test(Rooms::class)->call('edit', $this->room->id)->set('nonRefundablePercent', '80')->call('save')->assertHasErrors('nonRefundablePercent');
    }

    // ───────────────────────── change dates ─────────────────────────

    public function test_change_dates_cancels_the_old_booking_free_once_the_new_one_is_confirmed(): void
    {
        $old = $this->book();
        $this->assertEquals(20000 - 4200, app(WalletService::class)->balance($this->customer->fresh()));

        $new = $this->book(['replaces' => $old], 20);

        $this->assertSame('confirmed', $new->status);
        $this->assertSame($old->id, $new->replaces_reservation_id);
        $old->refresh();
        $this->assertSame('cancelled', $old->status);
        $this->assertEquals(0.0, (float) $old->cancellation_fee);
        $this->assertStringContainsString($new->code, (string) $old->cancellation_note);
        // Old ₹4,200 refunded in full, new ₹4,200 paid: back to one stay's worth.
        $this->assertEquals(20000 - 4200, app(WalletService::class)->balance($this->customer->fresh()));
    }

    public function test_the_old_booking_stays_while_the_hotel_has_not_accepted_the_new_one(): void
    {
        $old = $this->book();
        $this->hotel->update(['needs_acceptance' => true]);

        $new = $this->book(['replaces' => $old], 20);

        $this->assertSame('pending', $new->status);
        $this->assertSame('confirmed', $old->fresh()->status, 'A declined new booking must never cost the guest their room.');

        app(HotelStayService::class)->accept($new->fresh());
        $this->assertSame('cancelled', $old->fresh()->status);
    }

    public function test_non_refundable_or_late_bookings_cannot_change_dates(): void
    {
        $nr = $this->book(['non_refundable' => true]);
        try {
            $this->book(['replaces' => $nr], 20);
            $this->fail('A non-refundable booking cannot change dates.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('can no longer be changed', $e->getMessage());
        }

        $late = $this->book([], 10);
        Setting::set('hotel.free_cancel_hours', '720'); // the free time is over
        $this->expectExceptionMessage('can no longer be changed');
        $this->book(['replaces' => $late], 20);
    }

    public function test_another_guests_booking_cannot_be_replaced(): void
    {
        $old = $this->book();
        $other = User::create(['uuid' => (string) Str::uuid(), 'name' => 'B', 'phone' => '9'.fake()->numerify('#########'), 'role' => 'customer', 'status' => 'active']);
        app(WalletService::class)->credit($other, 9000, reason: 'top-up', ref: 'test-topup-'.Str::random(6));
        [$in, $out] = $this->dates(20);

        $this->expectExceptionMessage('can no longer be changed');
        app(HotelBookingService::class)->book($other, $this->hotel, $this->room, $this->plan, $in, $out, 1, 2, ['guest_name' => 'B'], 'wallet', null, 0, ['replaces' => $old]);
    }

    public function test_the_booking_page_sends_new_dates_to_the_booking_form(): void
    {
        $old = $this->book();
        [$in, $out] = $this->dates(20);

        Livewire::actingAs($this->customer)->test(BookingPage::class, ['code' => $old->code])
            ->assertSee('Change dates')
            ->set('newIn', $in)->set('newOut', $out)
            ->call('changeDates')
            ->assertRedirectContains('replace='.$old->code);
    }

    // ───────────────────────── search ─────────────────────────

    public function test_search_by_area_or_landmark(): void
    {
        $this->makeAccommodation($this->franchise, $this->zone, null, ['approval_status' => 'approved', 'name' => 'Lake Inn', 'address_line' => 'Beside the lake']);

        Livewire::withQueryParams(['q' => 'bus stand'])->test(Home::class, ['city' => 'nellore'])
            ->assertSee('Sea View Lodge')->assertDontSee('Lake Inn');
        Livewire::withQueryParams(['q' => 'lake'])->test(Home::class, ['city' => 'nellore'])
            ->assertSee('Lake Inn')->assertDontSee('Sea View Lodge')
            ->assertSee('data-testid="hotel-q-chip"', false)
            ->assertDontSee('data-testid="hotel-where"', false); // one search: no second box on the hotels page
    }

    /** Owner 2026-11-12: "we already have a search box in the top bar" — hotels are found there. */
    public function test_the_top_bar_search_finds_hotels_by_name_or_area(): void
    {
        Livewire::test(\App\Livewire\Customer\SearchBar::class)->set('term', 'bus stand')
            ->assertSee('data-testid="search-hotels"', false)->assertSee('Sea View Lodge');

        $this->get(route('customer.search', ['q' => 'sea view']))->assertOk()
            ->assertSee('data-testid="search-page-hotels"', false)->assertSee('Sea View Lodge')
            ->assertSee('/nellore/hotels?q=sea', false);

        // Hotels switched off in the city: no hotel results.
        app(\App\Services\ModuleActivationService::class)->setActive(\App\Support\Modules::HOTEL, 'franchise', $this->franchise->id, false);
        \Illuminate\Support\Facades\Cache::flush();
        Livewire::test(\App\Livewire\Customer\SearchBar::class)->set('term', 'bus stand')->assertDontSee('Sea View Lodge');
    }

    public function test_near_me_shows_the_distance_and_puts_the_nearest_first(): void
    {
        $far = $this->makeAccommodation($this->franchise, $this->zone, null, ['approval_status' => 'approved', 'name' => 'Far Away Inn', 'lat' => 14.60, 'lng' => 80.10, 'is_featured' => true]);
        $this->makeRatePlan($this->makeRoomType($far, ['total_inventory' => 1]), ['nightly_rate' => 1500]);

        $html = Livewire::test(Home::class, ['city' => 'nellore'])
            ->set('near', '14.4430,79.9870')
            ->assertSet('sort', 'distance')
            ->assertSee('km away')
            ->html();
        $this->assertLessThan(strpos($html, 'Far Away Inn'), strpos($html, 'Sea View Lodge'), 'Nearest first, even before a featured hotel.');

        Livewire::test(Home::class, ['city' => 'nellore'])->set('near', 'not a place')->assertDontSee('km away');
    }

    public function test_the_map_lists_every_hotel_with_its_pin_when_switched_on(): void
    {
        config(['services.google_maps.key' => 'test-browser-key']);

        Livewire::test(Home::class, ['city' => 'nellore'])
            ->assertSee('data-testid="hotel-map-toggle"', false)
            ->assertDontSee('data-testid="hotel-map"', false)
            ->set('showMap', true)
            ->assertSee('data-testid="hotel-map"', false)
            ->assertSee('14.4426', false)
            ->assertSee('Sea View Lodge');
    }

    public function test_distance_is_worked_out_correctly(): void
    {
        // Nellore RTC to Nellore railway station is about 2 km.
        $this->assertEqualsWithDelta(2.0, Home::km([14.4426, 79.9865], [14.4570, 79.9930]), 0.4);
        $this->assertNull(Home::point('91,10'));
        $this->assertSame([14.44, 79.98], Home::point(' 14.44 , 79.98 '));
    }
}
