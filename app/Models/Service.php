<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Service extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'services';

    protected $fillable = [
        'category_id',
        'subcategory_id',
        'external_id',
        'name',
        'slug',
        'description',
        'base_price',
        'discount_price',
        'price_type',
        'duration_estimate_mins',
        'cover_image',
        'is_active',
        'location_required',
        'age_restriction',
        'sort_order'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'location_required' => 'boolean',
        'age_restriction' => 'boolean',
    ];

    /**
     * `quote_on_inspection` shows as "Starts From" in the admin UI — Glover's
     * wording, and the clearer phrasing for what it means to a customer (the
     * price shown isn't final). The stored value stays as-is.
     */
    public const PRICE_TYPE_LABELS = [
        'fixed' => 'Fixed',
        'hourly' => 'Hourly',
        'quote_on_inspection' => 'Starts From',
    ];

    public function getPriceTypeLabelAttribute(): string
    {
        return self::PRICE_TYPE_LABELS[$this->price_type] ?? $this->price_type;
    }

    /**
     * Same dual-source handling as ServiceCategory::image_url — uploaded
     * files live on the public disk, Glover-imported rows carry full URLs.
     */
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

    public function category() { return $this->belongsTo(ServiceCategory::class, 'category_id'); }
    public function subcategory() { return $this->belongsTo(ServiceSubcategory::class, 'subcategory_id'); }
    public function optionGroups() { return $this->hasMany(ServiceOptionGroup::class); }
    public function franchisePricing() { return $this->hasMany(FranchiseServicePricing::class); }
    public function bookings() { return $this->hasMany(Booking::class); }
}
