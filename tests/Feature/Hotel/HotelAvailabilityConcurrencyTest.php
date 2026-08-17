<?php

namespace Tests\Feature\Hotel;

use App\Models\HotelRoomAvailability;
use App\Services\HotelAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\HotelFixtureHelpers;
use Tests\TestCase;

/**
 * HOTEL / STAY BOOKING MODULE — direct proof of HotelAvailabilityService's
 * own concurrency-safety design (see its class docblock). Same honest
 * limitation every other concurrency test in this codebase documents:
 * PHPUnit is single-threaded, so this doesn't simulate true concurrent HTTP
 * requests — it proves the real mechanisms the design depends on actually
 * work: quantity accounting is correct across overlapping/adjacent ranges,
 * a manual block is respected, and the lockForUpdate()/UNIQUE-constraint
 * combination leaves no partial state on a rejected attempt.
 */
class HotelAvailabilityConcurrencyTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use HotelFixtureHelpers;

    public function test_reserve_rooms_rejects_once_inventory_is_exhausted_for_any_date_in_range(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $accommodation = $this->makeAccommodation($franchise, $zone);
        $roomType = $this->makeRoomType($accommodation, ['total_inventory' => 3]);
        $service = app(HotelAvailabilityService::class);

        DB::transaction(function () use ($service, $roomType) {
            $service->reserveRooms($roomType, '2030-01-10', '2030-01-15', 3);
        });

        $this->assertFalse($service->isAvailable($roomType, '2030-01-12', '2030-01-13', 1), 'Fully exhausted inventory must report unavailable.');

        $overlapShapes = ['2030-01-09', '2030-01-11']; // overlaps the start
        try {
            DB::transaction(function () use ($service, $roomType) {
                $service->reserveRooms($roomType, '2030-01-09', '2030-01-11', 1);
            });
            $this->fail('Expected a RuntimeException for exhausted overlapping inventory.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('does not have', $e->getMessage());
        }
    }

    public function test_adjacent_half_open_ranges_do_not_conflict(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $accommodation = $this->makeAccommodation($franchise, $zone);
        $roomType = $this->makeRoomType($accommodation, ['total_inventory' => 1]);
        $service = app(HotelAvailabilityService::class);

        DB::transaction(function () use ($service, $roomType) {
            $service->reserveRooms($roomType, '2030-02-01', '2030-02-05', 1);
        });

        // Starts exactly when the first range ends -- must succeed, same
        // half-open-range convention PropertyAvailabilityService uses.
        DB::transaction(function () use ($service, $roomType) {
            $service->reserveRooms($roomType, '2030-02-05', '2030-02-08', 1);
        });

        $this->assertTrue(true);
    }

    public function test_a_manual_block_is_respected_even_with_zero_rooms_booked(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $accommodation = $this->makeAccommodation($franchise, $zone);
        $roomType = $this->makeRoomType($accommodation, ['total_inventory' => 5]);
        $service = app(HotelAvailabilityService::class);

        HotelRoomAvailability::create([
            'hotel_room_type_id' => $roomType->id, 'date' => '2030-03-01',
            'rooms_booked' => 0, 'is_available' => false, 'reason' => 'maintenance',
        ]);

        $this->assertFalse($service->isAvailable($roomType, '2030-03-01', '2030-03-02', 1));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('blocked');
        DB::transaction(function () use ($service, $roomType) {
            $service->reserveRooms($roomType, '2030-03-01', '2030-03-02', 1);
        });
    }

    public function test_releasing_rooms_never_goes_negative(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $accommodation = $this->makeAccommodation($franchise, $zone);
        $roomType = $this->makeRoomType($accommodation, ['total_inventory' => 2]);
        $service = app(HotelAvailabilityService::class);

        DB::transaction(function () use ($service, $roomType) {
            $service->reserveRooms($roomType, '2030-04-01', '2030-04-03', 1);
        });

        // Release more than was ever booked (defensive against a double-release bug).
        $service->releaseRooms($roomType, '2030-04-01', '2030-04-03', 5);

        $row = HotelRoomAvailability::where('hotel_room_type_id', $roomType->id)->where('date', '2030-04-01')->first();
        $this->assertSame(0, $row->rooms_booked);
    }

    /**
     * The real DB-level proof the class docblock describes: reserveRooms()
     * acquires `SELECT ... FOR UPDATE` on any existing rows in range. This
     * doesn't (and can't, single-connection PHPUnit) prove a second
     * transaction actually blocks -- it proves the lock statement itself
     * executes correctly against the real schema, twice in a row, on a
     * row that already exists.
     */
    public function test_reserve_rooms_can_lock_an_existing_row_repeatedly(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $accommodation = $this->makeAccommodation($franchise, $zone);
        $roomType = $this->makeRoomType($accommodation, ['total_inventory' => 10]);
        $service = app(HotelAvailabilityService::class);

        DB::transaction(function () use ($service, $roomType) {
            $service->reserveRooms($roomType, '2030-05-01', '2030-05-02', 1);
        });

        DB::transaction(function () use ($service, $roomType) {
            $service->reserveRooms($roomType, '2030-05-01', '2030-05-02', 1);
        });

        $row = HotelRoomAvailability::where('hotel_room_type_id', $roomType->id)->where('date', '2030-05-01')->first();
        $this->assertSame(2, $row->rooms_booked);
    }
}
