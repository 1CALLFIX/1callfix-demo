<?php

namespace Tests\Feature\Hotel;

use App\Models\Franchise;
use App\Models\HotelRoomAvailability;
use App\Models\Setting;
use App\Models\Zone;
use App\Support\HotelPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Support\HotelFixtureHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * Hotels H2 (owner 2026-11-12: "stand against the aggregators"): /{city}/hotels and the hotel page show only bookable
 * hotels, and every price includes taxes from the Super Admin GST bands — the same HotelPricing quote paid at checkout.
 */
class HotelCustomerPagesTest extends TestCase
{
    use RefreshDatabase;
    use LiveCity;
    use HotelFixtureHelpers;

    private Franchise $franchise;

    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        $city = $this->liveCity('Nellore');
        $this->franchise = Franchise::where('city_id', $city->id)->firstOrFail();
        $this->zone = Zone::where('franchise_id', $this->franchise->id)->firstOrFail();
        Setting::set('hotel.gst_slabs', "1000 : 0\n7500 : 5\n* : 18");
    }

    private function hotel(array $overrides = [], float $price = 2000, ?float $weekend = null)
    {
        $hotel = $this->makeAccommodation($this->franchise, $this->zone, null, $overrides + ['approval_status' => 'approved', 'description' => 'Near the station.']);
        $room = $this->makeRoomType($hotel, ['total_inventory' => 2, 'max_adults' => 2]);
        $plan = $this->makeRatePlan($room, ['nightly_rate' => $price, 'weekend_rate' => $weekend]);

        return [$hotel, $room, $plan];
    }

    public function test_hotels_page_is_404_until_hotels_is_switched_on_for_the_city(): void
    {
        $this->get('/nellore/hotels')->assertNotFound();

        $this->activateHotelFor($this->franchise);
        $this->get('/nellore/hotels')->assertOk();
    }

    public function test_list_shows_only_approved_active_hotels_with_the_price_including_taxes(): void
    {
        $this->activateHotelFor($this->franchise);
        [$live] = $this->hotel(['name' => 'Sea View Lodge']);
        $this->hotel(['name' => 'Pending Inn', 'approval_status' => 'pending']);
        $this->hotel(['name' => 'Switched Off Inn', 'is_active' => false]);

        $in = now()->addDays(10)->next(Carbon::TUESDAY)->toDateString();
        $out = Carbon::parse($in)->addDays(2)->toDateString();

        // ₹2,000 a night is in the 5% band: ₹2,100 a night with GST, ₹4,200 for 2 nights.
        $this->get("/nellore/hotels?in={$in}&out={$out}")->assertOk()
            ->assertSee('Sea View Lodge')->assertDontSee('Pending Inn')->assertDontSee('Switched Off Inn')
            ->assertSee('₹2,100')->assertSee('₹4,200 for 2 nights')->assertSee('taxes included');
    }

    public function test_quote_uses_calendar_price_then_weekend_price_then_normal_price_and_closed_dates_are_full(): void
    {
        [, $room, $plan] = $this->hotel([], 1000, 1500);
        $friday = now()->addDays(10)->next(Carbon::FRIDAY);
        $saturday = $friday->copy()->addDay();
        $sunday = $friday->copy()->addDays(2);
        HotelRoomAvailability::create(['hotel_room_type_id' => $room->id, 'date' => $saturday->toDateString(), 'price_override' => 3000, 'rooms_booked' => 0, 'is_available' => true, 'reason' => 'available']);

        $q = HotelPricing::quote($room->fresh(), $plan->fresh(), $friday->toDateString(), $sunday->addDay()->toDateString());
        $this->assertSame([1500.0, 3000.0, 1000.0], array_column($q['nights'], 'price'), 'Fri weekend, Sat special, Sun normal');
        // GST: 1500 → 5% = 75; 3000 → 5% = 150; 1000 → 0%.
        $this->assertEquals(225.0, $q['gst']);
        $this->assertEquals(5725.0, $q['total']);

        HotelRoomAvailability::where('hotel_room_type_id', $room->id)->where('date', $saturday->toDateString())->update(['is_available' => false]);
        $this->assertFalse(HotelPricing::quote($room->fresh(), $plan->fresh(), $friday->toDateString(), $sunday->toDateString())['available']);
    }

    public function test_no_gst_bands_means_plus_taxes_and_no_book_button(): void
    {
        $this->activateHotelFor($this->franchise);
        Setting::set('hotel.gst_slabs', null);
        [$hotel] = $this->hotel(['name' => 'Fail Closed Inn']);

        $this->get('/nellore/hotels/'.$hotel->slug)->assertOk()
            ->assertSee('+ taxes')->assertSee('Booking opens soon');
    }

    public function test_hotel_page_has_rooms_like_share_and_search_engine_markup(): void
    {
        $this->activateHotelFor($this->franchise);
        [$hotel, $room] = $this->hotel(['name' => 'Grand Nellore', 'facilities' => ['Free Wi-Fi', 'Parking']]);

        $this->get('/nellore/hotels/'.$hotel->slug)->assertOk()
            ->assertSee('Grand Nellore')->assertSee($room->name)->assertSee('Free Wi-Fi')
            ->assertSee('data-item-actions', false)
            ->assertSee('"@type":"Hotel"', false);
    }

    public function test_a_pending_hotels_page_is_not_found(): void
    {
        $this->activateHotelFor($this->franchise);
        [$hotel] = $this->hotel(['approval_status' => 'pending']);

        $this->get('/nellore/hotels/'.$hotel->slug)->assertNotFound();
    }
}
