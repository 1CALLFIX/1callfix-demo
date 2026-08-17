<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * HOTEL / STAY BOOKING MODULE -- the listing (catalog-shaped, like
 * `Property`/`Service`). `HotelReservation` is the order. See this
 * migration's own docblock for why there's no base-price column here --
 * price lives on `HotelRatePlan`, reached through `HotelRoomType`.
 */
class Accommodation extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'accommodations';

    protected $fillable = [
        'provider_id', 'accommodation_type_id', 'franchise_id', 'zone_id',
        'name', 'slug', 'description', 'address_line', 'lat', 'lng',
        'contact_phone', 'contact_email',
        'check_in_time_start', 'check_in_time_end', 'check_out_time',
        'policies', 'cover_image', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Accommodation $accommodation) {
            if (empty($accommodation->slug)) {
                $accommodation->slug = Str::slug($accommodation->name).'-'.Str::random(6);
            }
        });
    }

    /** Same dual-source (uploaded path vs. full URL) handling as Property::cover_image_url. */
    public function getCoverImageUrlAttribute(): ?string
    {
        if (! $this->cover_image) {
            return null;
        }

        if (Str::startsWith($this->cover_image, ['http://', 'https://', '//', 'data:'])) {
            return $this->cover_image;
        }

        return Storage::disk('public')->url($this->cover_image);
    }

    public function provider() { return $this->belongsTo(Provider::class); }
    public function accommodationType() { return $this->belongsTo(AccommodationType::class); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function amenities() { return $this->belongsToMany(AccommodationAmenity::class, 'accommodation_amenity_accommodation'); }
    public function roomTypes() { return $this->hasMany(HotelRoomType::class); }
    public function reservations() { return $this->hasMany(HotelReservation::class); }
}
