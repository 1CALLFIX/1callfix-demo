<?php

namespace App\Services;

use App\Models\HotelRoomAvailability;
use App\Models\HotelRoomType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * HOTEL / STAY BOOKING MODULE -- the room-type-quantity counterpart to
 * `PropertyAvailabilityService`. Same two-part concurrency-safety design
 * that class's own docblock explains in full (lockForUpdate() any EXISTING
 * rows in the requested range, plus the table's own
 * unique(['hotel_room_type_id', 'date']) constraint as the backstop for a
 * genuine first-touch race), extended with the one real difference a
 * multi-room hotel booking needs: a QUANTITY per date, not a boolean.
 *
 * `hotel_room_availabilities` is sparse the same way `property_availabilities`
 * is -- a date with no row means "rooms_booked = 0" (nothing consumed yet).
 */
class HotelAvailabilityService
{
    /**
     * Read-only check -- for search/browse/quote, NOT the authoritative
     * safety mechanism. Only reserveRooms() below, called inside the
     * reservation's own transaction, is actually safe against a race.
     */
    public function isAvailable(HotelRoomType $roomType, string $checkIn, string $checkOut, int $requestedCount): bool
    {
        $dates = $this->dateRange($checkIn, $checkOut);

        $rows = HotelRoomAvailability::where('hotel_room_type_id', $roomType->id)
            ->whereIn('date', $dates)
            ->get()
            ->keyBy(fn ($row) => $row->date);

        foreach ($dates as $date) {
            $row = $rows->get($date);
            $booked = $row->rooms_booked ?? 0;
            $blocked = $row && ! $row->is_available;

            if ($blocked || ($booked + $requestedCount) > $roomType->total_inventory) {
                return false;
            }
        }

        return true;
    }

    /**
     * The real, transactional, concurrency-safe reservation of a quantity
     * of rooms across a date range. MUST be called from inside the
     * caller's own DB::transaction() (CreateHotelReservationAction does
     * this), same requirement PropertyAvailabilityService::reserveDates()
     * documents.
     *
     * @throws \RuntimeException if any date in the range cannot hold the requested count
     */
    public function reserveRooms(HotelRoomType $roomType, string $checkIn, string $checkOut, int $requestedCount): void
    {
        $dates = $this->dateRange($checkIn, $checkOut);

        // Step 1: lock any EXISTING rows for these dates, serializing
        // concurrent access to whichever dates already have one.
        $existing = HotelRoomAvailability::where('hotel_room_type_id', $roomType->id)
            ->whereIn('date', $dates)
            ->lockForUpdate()
            ->get()
            ->keyBy(fn ($row) => $row->date);

        foreach ($dates as $date) {
            $row = $existing->get($date);
            $booked = $row->rooms_booked ?? 0;
            $blocked = $row && ! $row->is_available;

            if ($blocked) {
                throw new \RuntimeException("Room type [{$roomType->id}] is blocked on {$date}.");
            }

            if (($booked + $requestedCount) > $roomType->total_inventory) {
                throw new \RuntimeException("Room type [{$roomType->id}] does not have {$requestedCount} room(s) available on {$date}.");
            }
        }

        // Step 2: for every date, either update the now-locked existing
        // row or insert a fresh one -- a fresh insert is where the UNIQUE
        // constraint becomes the real backstop against a genuine race
        // (see class docblock).
        foreach ($dates as $date) {
            if ($existing->has($date)) {
                $existing[$date]->increment('rooms_booked', $requestedCount);
            } else {
                HotelRoomAvailability::create([
                    'hotel_room_type_id' => $roomType->id, 'date' => $date,
                    'rooms_booked' => $requestedCount, 'is_available' => true, 'reason' => 'available',
                ]);
            }
        }
    }

    /** The inverse of reserveRooms() -- releases a cancelled/line's room count back to available. */
    public function releaseRooms(HotelRoomType $roomType, string $checkIn, string $checkOut, int $count): void
    {
        $dates = $this->dateRange($checkIn, $checkOut);

        HotelRoomAvailability::where('hotel_room_type_id', $roomType->id)
            ->whereIn('date', $dates)
            ->lockForUpdate()
            ->get()
            ->each(function (HotelRoomAvailability $row) use ($count) {
                $row->update(['rooms_booked' => max(0, $row->rooms_booked - $count)]);
            });
    }

    /** [start, end) -- check-out day itself is not occupied, same half-open convention PropertyAvailabilityService::dateRange() uses. */
    public function dateRange(string $checkIn, string $checkOut): Collection
    {
        $dates = collect();
        $current = Carbon::parse($checkIn)->startOfDay();
        $end = Carbon::parse($checkOut)->startOfDay();

        while ($current->lt($end)) {
            $dates->push($current->toDateString());
            $current->addDay();
        }

        return $dates;
    }
}
