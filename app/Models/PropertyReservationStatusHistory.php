<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyReservationStatusHistory extends Model
{
    protected $table = 'property_reservation_status_history';

    protected $fillable = ['property_reservation_id', 'status', 'changed_by', 'note', 'changed_at'];

    protected $casts = ['changed_at' => 'datetime'];

    public function reservation() { return $this->belongsTo(PropertyReservation::class, 'property_reservation_id'); }
    public function changedByUser() { return $this->belongsTo(User::class, 'changed_by'); }
}
