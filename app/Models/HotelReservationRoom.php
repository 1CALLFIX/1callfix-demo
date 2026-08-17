<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** HOTEL / STAY BOOKING MODULE -- one room-type/rate-plan line within a HotelReservation. See this table's own migration docblock for the multi-room reasoning. */
class HotelReservationRoom extends Model
{
    protected $table = 'hotel_reservation_rooms';

    protected $fillable = [
        'hotel_reservation_id', 'hotel_room_type_id', 'hotel_rate_plan_id',
        'room_count', 'nightly_rate_snapshot', 'line_total',
    ];

    public function reservation() { return $this->belongsTo(HotelReservation::class, 'hotel_reservation_id'); }
    public function roomType() { return $this->belongsTo(HotelRoomType::class, 'hotel_room_type_id'); }
    public function ratePlan() { return $this->belongsTo(HotelRatePlan::class, 'hotel_rate_plan_id'); }
}
