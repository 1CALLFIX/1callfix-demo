<?php

namespace Tests\Feature\Hotel;

use App\Actions\RegisterHotelAction;
use App\Livewire\Accommodations\Manage as AccommodationsManage;
use App\Livewire\Hotel\Calendar;
use App\Livewire\Hotel\Details;
use App\Livewire\Hotel\Rooms;
use App\Livewire\Hotels\Settings as HotelSettingsPage;
use App\Models\Accommodation;
use App\Models\AccommodationType;
use App\Models\HotelRatePlan;
use App\Models\HotelRoomAvailability;
use App\Models\Setting;
use App\Models\User;
use App\Support\HotelSettings;
use App\Support\PartnerPage\PartnerPageData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\HotelFixtureHelpers;
use Tests\TestCase;

/**
 * Hotels H1 (owner 2026-11-12): a hotel joins through /partners, waits for approval, and runs its own hotel portal —
 * details and photos, rooms and prices, and a room calendar. Every rule value is a Super Admin setting.
 */
class HotelPartnerPortalTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use HotelFixtureHelpers;

    private function hotelOwner(array $overrides = []): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $hotel = $this->makeAccommodation($franchise, $zone, null, $overrides);

        return [$hotel->provider->user, $hotel, $zone];
    }

    // ── settings ──

    public function test_gst_bands_are_read_from_settings_and_fail_closed_when_blank(): void
    {
        $this->assertNull(HotelSettings::gstPercentFor(1500), 'No bands set: nobody can book.');

        Setting::set('hotel.gst_slabs', "1000 : 0\n7500 : 5\n* : 18");
        $this->assertSame(0.0, HotelSettings::gstPercentFor(999));
        $this->assertSame(5.0, HotelSettings::gstPercentFor(7500));
        $this->assertSame(18.0, HotelSettings::gstPercentFor(9000));

        $this->assertNotNull(HotelSettings::validate('hotel.gst_slabs', "1000 - 0"));
        $this->assertNull(HotelSettings::validate('hotel.gst_slabs', "1000 : 0\n* : 12%"));
    }

    public function test_hotel_settings_page_is_super_admin_only(): void
    {
        Livewire::actingAs($this->makeUserWithNoPermissions())->test(HotelSettingsPage::class)->assertForbidden();
        Livewire::actingAs($this->makeSuperAdmin())->test(HotelSettingsPage::class)->assertOk()->assertSee('Free cancellation until');
    }

    // ── joining ──

    public function test_partner_page_hotel_card_opens_sign_up_only_when_super_admin_takes_hotels(): void
    {
        $card = fn () => collect(PartnerPageData::roles())->firstWhere('code', 'hotel');
        if ($card() === null) {
            $this->markTestSkipped('No hotel card in this registry.');
        }
        $this->assertFalse($card()['hands_off']);

        Setting::set('hotel.recruiting_hotels', '1');
        $this->assertTrue($card()['hands_off']);
    }

    public function test_sign_up_creates_a_pending_switched_off_hotel_and_refuses_home_stays_while_off(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        foreach (['hotel' => 'Hotel', 'homestay' => 'Homestay'] as $slug => $name) {
            AccommodationType::firstOrCreate(['slug' => $slug], ['name' => $name, 'is_active' => true]);
        }

        $hotel = app(RegisterHotelAction::class)->execute(
            zone: $zone, hotelName: 'Sea View Lodge', typeSlug: 'hotel', existing: null,
            registration: ['address' => 'Beach Road, Nellore'], name: 'Ravi', nationalPhone: '9'.fake()->numerify('#########'),
            plainPassword: 'secret-pass-1',
        );
        $this->assertSame('pending', $hotel->approval_status);
        $this->assertFalse($hotel->is_active);
        $this->assertTrue($hotel->provider->user->holdsPartnerRole('hotel'));
        $this->assertFalse($hotel->provider->user->holdsPartnerRole('service'), 'A hotel-only account is not a service partner.');
        $this->assertFalse($hotel->provider->user->canApplyAsPartner('hotel'), 'One hotel per account through sign-up.');

        $this->expectException(\RuntimeException::class);
        app(RegisterHotelAction::class)->execute(
            zone: $zone, hotelName: 'My Home', typeSlug: 'homestay', existing: null,
            registration: ['address' => 'Somewhere'], name: 'Sita', nationalPhone: '9'.fake()->numerify('#########'), plainPassword: 'secret-pass-1',
        );
    }

    public function test_sign_up_page_asks_for_the_hotel_name_and_kind_when_handed_a_hotel_lead(): void
    {
        Setting::set('hotel.recruiting_hotels', '1');
        AccommodationType::firstOrCreate(['slug' => 'hotel'], ['name' => 'Hotel', 'is_active' => true]);
        if (collect(PartnerPageData::roles())->firstWhere('code', 'hotel') === null) {
            $this->markTestSkipped('No hotel card in this registry.');
        }
        $lead = \App\Models\PartnerLead::create(['name' => 'Ravi', 'phone' => '9876543210', 'city' => 'Nellore', 'role' => 'hotel', 'status' => 'handed_off', 'source' => 'partner_page', 'consent_at' => now(), 'consent_text' => 'ok']);
        session()->put('partner_lead', ['id' => $lead->id, 'role' => 'hotel', 'city' => '']);

        Livewire::test(\App\Livewire\Provider\Auth\Register::class)
            ->assertSet('partnerRole', 'hotel')
            ->assertSee('List your hotel')->assertSee('Hotel name')->assertSee('Kind of stay');
    }

    // ── portal ──

    public function test_only_a_hotel_owner_opens_the_portal(): void
    {
        [$owner] = $this->hotelOwner();
        foreach (['hotel.dashboard', 'hotel.rooms', 'hotel.calendar', 'hotel.details'] as $route) {
            $this->actingAs($owner)->get(route($route))->assertOk();
        }

        $customer = User::create(['uuid' => (string) Str::uuid(), 'name' => 'C', 'phone' => '9'.fake()->numerify('#########'), 'role' => 'customer', 'status' => 'active']);
        $this->actingAs($customer)->get(route('hotel.dashboard'))->assertForbidden();
    }

    public function test_pending_hotel_sees_waiting_and_the_checklist(): void
    {
        [$owner] = $this->hotelOwner(['approval_status' => 'pending', 'is_active' => false]);

        $this->actingAs($owner)->get(route('hotel.dashboard'))->assertOk()
            ->assertSee('Waiting for approval')->assertSee('At least one room with a price');
    }

    public function test_adding_a_room_saves_the_kind_count_and_price(): void
    {
        [$owner, $hotel] = $this->hotelOwner();

        Livewire::actingAs($owner)->test(Rooms::class)
            ->call('add')
            ->set('roomName', 'Deluxe AC')->set('inventory', '4')->set('maxAdults', '2')->set('price', '1499')->set('mealPlan', 'breakfast')
            ->call('save')->assertHasNoErrors()->assertSee('Deluxe AC');

        $room = $hotel->roomTypes()->firstWhere('name', 'Deluxe AC');
        $this->assertSame(4, (int) $room->total_inventory);
        $plan = HotelRatePlan::where('hotel_room_type_id', $room->id)->first();
        $this->assertEquals(1499.0, (float) $plan->nightly_rate);
        $this->assertSame('breakfast', $plan->meal_plan);
    }

    public function test_a_hotel_cannot_touch_another_hotels_room(): void
    {
        [$owner] = $this->hotelOwner();
        [, $other] = $this->hotelOwner();
        $foreign = $this->makeRoomType($other);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::actingAs($owner)->test(Rooms::class)->call('edit', $foreign->id);
    }

    public function test_calendar_closes_dates_and_sets_a_special_price_without_touching_bookings(): void
    {
        [$owner, $hotel] = $this->hotelOwner();
        $room = $this->makeRoomType($hotel, ['total_inventory' => 3]);
        $this->makeRatePlan($room, ['nightly_rate' => 1000]);
        $day = now()->addDays(3)->toDateString();
        HotelRoomAvailability::create(['hotel_room_type_id' => $room->id, 'date' => $day, 'rooms_booked' => 2, 'is_available' => true, 'reason' => 'available']);

        Livewire::actingAs($owner)->test(Calendar::class)
            ->set('roomTypeId', $room->id)->set('from', $day)->set('to', $day)->set('action', 'close')->call('apply')->assertHasNoErrors()
            ->set('action', 'price')->set('price', '1800')->call('apply')->assertHasNoErrors();

        $row = HotelRoomAvailability::where('hotel_room_type_id', $room->id)->where('date', $day)->first();
        $this->assertFalse($row->is_available);
        $this->assertSame(2, (int) $row->rooms_booked, 'Rooms already booked stay booked.');
        $this->assertEquals(1800.0, (float) $row->price_override);
    }

    public function test_details_save_refuses_a_facility_not_on_the_super_admin_list(): void
    {
        [$owner] = $this->hotelOwner();

        Livewire::actingAs($owner)->test(Details::class)
            ->set('facilities', ['Free Wi-Fi', 'Helipad'])->call('save')->assertHasErrors('facilities.1');
        Livewire::actingAs($owner)->test(Details::class)
            ->set('facilities', ['Free Wi-Fi'])->set('description', 'Near the bus stand.')->call('save')->assertHasNoErrors();
    }

    // ── admin approval ──

    public function test_admin_approves_only_a_ready_hotel_and_reject_needs_a_reason(): void
    {
        [, $hotel, $zone] = $this->hotelOwner(['approval_status' => 'pending', 'is_active' => false, 'lat' => 14.44, 'lng' => 79.98]);
        $admin = $this->makeSuperAdmin();
        Setting::set('hotel.min_photos', '0');

        Livewire::actingAs($admin)->test(AccommodationsManage::class)->call('approve', $hotel->id);
        $this->assertSame('pending', $hotel->fresh()->approval_status, 'Not ready: no description, no priced room.');

        Livewire::actingAs($admin)->test(AccommodationsManage::class)->set('rejectReason', '')->call('reject', $hotel->id)->assertHasErrors('rejectReason');

        $hotel->update(['description' => 'Clean rooms near the station.']);
        $this->makeRatePlan($this->makeRoomType($hotel), ['nightly_rate' => 999]);
        Livewire::actingAs($admin)->test(AccommodationsManage::class)->call('approve', $hotel->id);
        $fresh = $hotel->fresh();
        $this->assertSame('approved', $fresh->approval_status);
        $this->assertTrue($fresh->is_active);
    }

    public function test_admin_cannot_switch_on_a_hotel_that_is_not_approved(): void
    {
        [, $hotel] = $this->hotelOwner(['approval_status' => 'pending', 'is_active' => false]);

        Livewire::actingAs($this->makeSuperAdmin())->test(AccommodationsManage::class)
            ->call('editAccommodation', $hotel->id)->set('editIsActive', true)->call('saveEdit')->assertHasErrors('editIsActive');
        $this->assertFalse($hotel->fresh()->is_active);
    }

    // ── test login ──

    public function test_test_accounts_makes_an_approved_hotel_that_signs_in_to_its_portal_and_disable_switches_it_off(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        $this->artisan('test-accounts', ['action' => 'create', '--password' => 'Test-pass-123', '--franchise' => $franchise->id])->assertSuccessful();

        $user = User::where('email', 'test.hotel'.\App\Console\Commands\TestAccountsCommand::DOMAIN)->firstOrFail();
        $hotel = Accommodation::where('provider_id', $user->providerProfile->id)->firstOrFail();
        $this->assertSame('approved', $hotel->approval_status);
        $this->assertTrue($hotel->is_active);
        $this->assertSame(2, $hotel->roomTypes()->count());

        Livewire::test(\App\Livewire\Provider\Auth\Login::class)
            ->set('identifier', $user->email)->set('password', 'Test-pass-123')->call('login')
            ->assertRedirect(route('hotel.dashboard'));

        $this->artisan('test-accounts', ['action' => 'disable'])->assertSuccessful();
        $this->assertFalse($hotel->fresh()->is_active);
    }
}
