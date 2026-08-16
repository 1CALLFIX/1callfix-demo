<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Phase 22.7 (Property Rental) — the listing (catalog-shaped, like
 * `Service`), not an order. `PropertyReservation` is the order.
 */
class Property extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'properties';

    protected $fillable = [
        'provider_id', 'property_type_id', 'franchise_id', 'zone_id',
        'name', 'slug', 'description', 'address_line', 'lat', 'lng',
        'base_price', 'discount_price', 'max_guests', 'bedrooms', 'bathrooms', 'beds',
        'check_in_time_start', 'check_in_time_end', 'check_out_time',
        'minimum_nights', 'maximum_nights', 'house_rules', 'cover_image', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Property $property) {
            if (empty($property->slug)) {
                $property->slug = Str::slug($property->name).'-'.Str::random(6);
            }
        });
    }

    /** Same dual-source (uploaded path vs. full URL) handling as ServiceCategory::image_url. */
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
    public function propertyType() { return $this->belongsTo(PropertyType::class); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function amenities() { return $this->belongsToMany(PropertyAmenity::class, 'property_amenity_property'); }
    public function availabilities() { return $this->hasMany(PropertyAvailability::class); }
    public function reservations() { return $this->hasMany(PropertyReservation::class); }

    /**
     * Dates within [start, end) that have an explicit is_available=false
     * row — the sparse-default model: a date with NO row is available.
     */
    public function unavailableDatesBetween(string $start, string $end): \Illuminate\Support\Collection
    {
        return $this->availabilities()
            ->where('is_available', false)
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<', $end)
            ->pluck('date');
    }
}
