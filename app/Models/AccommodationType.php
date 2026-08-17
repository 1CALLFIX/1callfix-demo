<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * HOTEL / STAY BOOKING MODULE -- the accommodation-type taxonomy
 * (hotel/resort/guest_house/homestay/hostel/serviced_apartment, seeded by
 * this table's own migration). A plain admin-extensible lookup, same
 * pattern as `PropertyType`.
 */
class AccommodationType extends Model
{
    protected $table = 'accommodation_types';

    protected $fillable = ['name', 'slug', 'icon', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function accommodations()
    {
        return $this->hasMany(Accommodation::class);
    }
}
