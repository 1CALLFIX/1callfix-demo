<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** HOTEL / STAY BOOKING MODULE -- a room type within one Accommodation (Standard/Deluxe/Suite/...). */
class HotelRoomType extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'hotel_room_types';

    protected $fillable = [
        'accommodation_id', 'name', 'description',
        'max_adults', 'max_children', 'bed_configuration',
        'total_inventory', 'cover_image', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function accommodation() { return $this->belongsTo(Accommodation::class); }
    public function ratePlans() { return $this->hasMany(HotelRatePlan::class); }
    public function availabilities() { return $this->hasMany(HotelRoomAvailability::class); }

    /**
     * Dates within [start, end) that are explicitly `is_available=false`
     * (manually blocked) -- the same sparse-default semantics
     * `Property::unavailableDatesBetween()` uses. Does NOT reflect
     * quantity exhaustion (rooms_booked >= total_inventory) -- that's a
     * separate check, see HotelAvailabilityService::isAvailable().
     */
    public function blockedDatesBetween(string $start, string $end): \Illuminate\Support\Collection
    {
        return $this->availabilities()
            ->where('is_available', false)
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<', $end)
            ->pluck('date');
    }
}
