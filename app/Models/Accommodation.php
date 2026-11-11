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
        // Hotels H1 (2026-11-12): partner sign-up + approval.
        'approval_status', 'rejection_reason', 'approved_at', 'gst_number', 'photos', 'facilities',
        'needs_acceptance', 'is_featured',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'approved_at' => 'datetime',
        'photos' => 'array',
        'facilities' => 'array',
        'needs_acceptance' => 'boolean',
        'is_featured' => 'boolean',
    ];

    /** Approved, switched on and (for private homes) home stays allowed: customers may see and book it. */
    public function scopeBookable($query)
    {
        $query->where('is_active', true)->where('approval_status', 'approved');
        if (! \App\Support\HotelSettings::homeStaysAllowed()) {
            $query->whereHas('accommodationType', fn ($t) => $t->whereNotIn('slug', \App\Support\HotelSettings::HOME_STAY_TYPES));
        }

        return $query;
    }

    public function isApproved(): bool
    {
        return $this->approval_status === 'approved';
    }

    /** This hotel's own rule when set, otherwise Hotel Settings for its area. */
    public function needsAcceptance(): bool
    {
        return $this->needs_acceptance ?? \App\Support\HotelSettings::on('hotel.needs_acceptance', array_filter(['zone_id' => $this->zone_id, 'franchise_id' => $this->franchise_id]));
    }

    /** Cover + extra hotel photos + every room's photos: what "needs N photos before approval" counts. */
    public function photoCount(): int
    {
        $rooms = $this->roomTypes()->get(['cover_image', 'photos'])
            ->sum(fn ($r) => ($r->cover_image ? 1 : 0) + count($r->photos ?? []));

        return ($this->cover_image ? 1 : 0) + count($this->photos ?? []) + $rooms;
    }

    /** @return list<string> public URLs of the cover and extra photos, cover first. */
    public function photoUrls(): array
    {
        return array_values(array_filter(array_map(
            fn ($p) => $p ? (Str::startsWith($p, ['http://', 'https://', '//', 'data:']) ? $p : Storage::disk('public')->url($p)) : null,
            [$this->cover_image, ...($this->photos ?? [])],
        )));
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Accommodation $accommodation) {
            if (empty($accommodation->slug)) {
                // Owner link rule: a clean, readable slug; -2, -3 only on a repeat (never a random code).
                $base = Str::slug($accommodation->name) ?: 'accommodation';
                $slug = $base;
                for ($n = 2; static::withTrashed()->where('slug', $slug)->exists(); $n++) {
                    $slug = $base.'-'.$n;
                }
                $accommodation->slug = $slug;
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
