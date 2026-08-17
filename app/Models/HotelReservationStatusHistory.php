<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** HOTEL / STAY BOOKING MODULE -- direct mirror of PropertyReservationStatusHistory/RentalReservationStatusHistory's own shape. */
class HotelReservationStatusHistory extends Model
{
    protected $table = 'hotel_reservation_status_history';

    protected $fillable = ['hotel_reservation_id', 'status', 'changed_by', 'note', 'changed_at'];

    protected $casts = ['changed_at' => 'datetime'];

    public function reservation() { return $this->belongsTo(HotelReservation::class, 'hotel_reservation_id'); }
    public function changedBy() { return $this->belongsTo(User::class, 'changed_by'); }
}
