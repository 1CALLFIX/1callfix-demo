<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxiRideStatusHistory extends Model
{
    protected $table = 'taxi_ride_status_history';

    protected $fillable = ['taxi_ride_id', 'status', 'changed_by', 'note', 'changed_at'];

    protected $casts = ['changed_at' => 'datetime'];

    public function taxiRide() { return $this->belongsTo(TaxiRide::class); }
    public function changedByUser() { return $this->belongsTo(User::class, 'changed_by'); }
}
