<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** RENTAL MODULE IMPLEMENTATION -- Vehicle taxonomy (Car/Bike/Scooter/Van/Other). */
class VehicleCategory extends Model
{
    protected $table = 'vehicle_categories';

    protected $fillable = ['name', 'slug', 'icon', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function vehicles() { return $this->hasMany(Vehicle::class); }
}
