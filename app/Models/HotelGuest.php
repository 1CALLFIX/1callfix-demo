<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** HOTEL / STAY BOOKING MODULE -- a named guest on a HotelReservation, distinct from the booking owner (`hotel_reservations.customer_id`). */
class HotelGuest extends Model
{
    protected $table = 'hotel_guests';

    protected $fillable = ['hotel_reservation_id', 'name', 'guest_type', 'is_primary', 'phone', 'email'];

    protected $casts = ['is_primary' => 'boolean'];

    public function reservation() { return $this->belongsTo(HotelReservation::class, 'hotel_reservation_id'); }
}
