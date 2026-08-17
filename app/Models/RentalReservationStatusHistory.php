<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RentalReservationStatusHistory extends Model
{
    protected $table = 'rental_reservation_status_history';

    protected $fillable = ['rental_reservation_id', 'status', 'changed_by', 'note', 'changed_at'];

    protected $casts = ['changed_at' => 'datetime'];

    public function reservation() { return $this->belongsTo(RentalReservation::class, 'rental_reservation_id'); }
    public function changedByUser() { return $this->belongsTo(User::class, 'changed_by'); }
}
