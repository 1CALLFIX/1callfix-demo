<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** HOTEL / STAY BOOKING MODULE -- see this table's own migration docblock for the quantity-based inventory model. */
class HotelRoomAvailability extends Model
{
    protected $table = 'hotel_room_availabilities';

    protected $fillable = [
        'hotel_room_type_id', 'date', 'rooms_booked', 'is_available', 'price_override', 'reason',
    ];

    /**
     * `date` is deliberately NOT cast to `'date'` -- same real bug
     * `PropertyAvailability` already found and documented (see that
     * model's own docblock): Eloquent's `date` cast still serializes for
     * storage using the model's full `Y-m-d H:i:s` `dateFormat`, so a
     * plain 'Y-m-d'-string `whereIn('date', $dates)` lookup silently fails
     * to match a cast-written row, `lockForUpdate()` finds nothing to
     * lock, and the code falls through to a fresh INSERT that collides
     * with the real existing row's UNIQUE constraint. Kept as a plain
     * string throughout (`HotelAvailabilityService` always compares/writes
     * plain 'Y-m-d' strings) -- same proven-correct fix, not a new one.
     */
    protected $casts = [
        'is_available' => 'boolean',
    ];

    public function roomType() { return $this->belongsTo(HotelRoomType::class, 'hotel_room_type_id'); }
}
