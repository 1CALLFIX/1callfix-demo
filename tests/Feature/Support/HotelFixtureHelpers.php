<?php

namespace Tests\Feature\Support;

use App\Models\Accommodation;
use App\Models\AccommodationType;
use App\Models\HotelRatePlan;
use App\Models\HotelReservation;
use App\Models\HotelRoomType;
use App\Models\Module;
use App\Models\Provider;
use App\Models\User;
use App\Services\ModuleActivationService;
use App\Support\Modules;
use Illuminate\Support\Str;

/** HOTEL / STAY BOOKING MODULE — the HotelReservation counterpart to PropertyRentalFixtureHelpers/RentalFixtureHelpers. */
trait HotelFixtureHelpers
{
    protected function enableHotelModuleForTests(): void
    {
        Module::where('code', Modules::HOTEL)->update(['is_implemented' => true]);
    }

    protected function activateHotelFor($franchise): void
    {
        $this->enableHotelModuleForTests();
        app(ModuleActivationService::class)->setActive(Modules::HOTEL, 'franchise', $franchise->id, true);
    }

    protected function makeAccommodationType(): AccommodationType
    {
        return AccommodationType::first() ?? AccommodationType::create(['name' => 'Hotel', 'slug' => 'hotel-'.Str::random(8), 'is_active' => true]);
    }

    protected function makeAccommodationOwner($franchise, $zone): Provider
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Owner', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id,
        ]);

        return Provider::create([
            'user_id' => $user->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true,
        ]);
    }

    protected function makeAccommodation($franchise, $zone, ?Provider $owner = null, array $overrides = []): Accommodation
    {
        $owner ??= $this->makeAccommodationOwner($franchise, $zone);
        $type = $this->makeAccommodationType();

        return Accommodation::create(array_merge([
            'provider_id' => $owner->id, 'accommodation_type_id' => $type->id,
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'name' => 'Test Hotel '.Str::random(6),
            'address_line' => '123 Test Street', 'lat' => 1.0, 'lng' => 1.0,
            'is_active' => true,
        ], $overrides));
    }

    protected function makeRoomType(Accommodation $accommodation, array $overrides = []): HotelRoomType
    {
        return HotelRoomType::create(array_merge([
            'accommodation_id' => $accommodation->id,
            'name' => 'Deluxe Room '.Str::random(4),
            'max_adults' => 2, 'max_children' => 1,
            'total_inventory' => 5,
            'is_active' => true,
        ], $overrides));
    }

    protected function makeRatePlan(HotelRoomType $roomType, array $overrides = []): HotelRatePlan
    {
        return HotelRatePlan::create(array_merge([
            'hotel_room_type_id' => $roomType->id,
            'name' => 'Standard Rate',
            'meal_plan' => 'room_only',
            'cancellation_policy_label' => 'flexible',
            'nightly_rate' => 1000,
            'is_active' => true,
        ], $overrides));
    }

    /** @return array{country:mixed,city:mixed,franchise:mixed,zone:mixed,customer:User,owner:Provider,accommodation:Accommodation,roomType:HotelRoomType,ratePlan:HotelRatePlan,reservation:HotelReservation} */
    protected function makeHotelReservationScenario(string $status = 'pending'): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $owner = $this->makeAccommodationOwner($franchise, $zone);
        $accommodation = $this->makeAccommodation($franchise, $zone, $owner);
        $roomType = $this->makeRoomType($accommodation);
        $ratePlan = $this->makeRatePlan($roomType, ['nightly_rate' => 1000]);

        $reservation = HotelReservation::create([
            'code' => 'HTST-'.now()->format('dm').'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'accommodation_id' => $accommodation->id,
            'check_in_date' => now()->addDays(5)->toDateString(),
            'check_out_date' => now()->addDays(7)->toDateString(),
            'number_of_nights' => 2, 'number_of_rooms' => 1, 'number_of_adults' => 2, 'number_of_children' => 0,
            'status' => $status, 'price_quoted' => 2000, 'payment_status' => 'pending', 'payment_method' => 'online',
        ]);

        \App\Models\HotelReservationRoom::create([
            'hotel_reservation_id' => $reservation->id,
            'hotel_room_type_id' => $roomType->id,
            'hotel_rate_plan_id' => $ratePlan->id,
            'room_count' => 1, 'nightly_rate_snapshot' => 1000, 'line_total' => 2000,
        ]);

        return compact('country', 'city', 'franchise', 'zone', 'customer', 'owner', 'accommodation', 'roomType', 'ratePlan', 'reservation');
    }
}
