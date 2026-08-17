<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** HOTEL / STAY BOOKING MODULE -- minimal amenity taxonomy, same pattern as `PropertyAmenity`. */
class AccommodationAmenity extends Model
{
    protected $table = 'accommodation_amenities';

    protected $fillable = ['name', 'icon', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function accommodations()
    {
        return $this->belongsToMany(Accommodation::class, 'accommodation_amenity_accommodation');
    }
}
