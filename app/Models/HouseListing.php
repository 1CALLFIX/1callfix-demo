<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Property for rent (owner 2026-11-14): a flat, house, portion, room or PG an owner lets by the month. Owned by a
 * Provider account (the one partner sign-in, documents and approval every partner uses).
 *
 * Status: draft → in_review → approved | rejected ("Needs changes", editable again). Once approved, rent, deposit,
 * facilities, rules and dates change at once; photos, description and the address wait in `pending_changes` for the
 * team while the listing stays up with the old ones (same rule as halls and shops). "Rented" hides it; "Still
 * available" brings it back for another Rental Settings period.
 */
class HouseListing extends Model
{
    public const STATUS_LABELS = [
        'draft' => 'Draft', 'in_review' => 'Waiting for review', 'approved' => 'Approved', 'rejected' => 'Needs changes',
    ];

    /** Fields of a live listing that wait for the team before customers see them. */
    public const REVIEWED = ['about', 'address_line', 'landmark', 'lat', 'lng', 'cover_image', 'photos'];

    public const MAX_PHOTOS = 12;

    protected $fillable = [
        'provider_id', 'franchise_id', 'zone_id', 'title', 'slug', 'home_type', 'size', 'area', 'floor', 'facing', 'furnishing',
        'tenants', 'rent', 'deposit', 'maintenance', 'available_from', 'landmark', 'address_line', 'lat', 'lng', 'amenities',
        'rule_list', 'about', 'cover_image', 'photos', 'contact_phone', 'status', 'rejection_reason', 'submitted_at',
        'approved_at', 'is_active', 'is_featured', 'rented_at', 'expires_at', 'pending_changes', 'pending_requested_at',
        'pending_rejection',
    ];

    protected $casts = [
        'tenants' => 'array',
        'amenities' => 'array',
        'rule_list' => 'array',
        'photos' => 'array',
        'pending_changes' => 'array',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'rent' => 'decimal:2',
        'deposit' => 'decimal:2',
        'maintenance' => 'decimal:2',
        'area' => 'integer',
        'available_from' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rented_at' => 'datetime',
        'expires_at' => 'datetime',
        'pending_requested_at' => 'datetime',
    ];

    public function provider() { return $this->belongsTo(Provider::class); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function visits() { return $this->hasMany(HouseVisit::class); }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }

    /** Customers see it: approved, switched on, not rented, not expired. */
    public function isLive(): bool
    {
        return $this->status === 'approved' && $this->is_active && $this->rented_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? Str::headline((string) $this->status);
    }

    public static function imageUrl(?string $path): ?string
    {
        return $path === null || $path === '' ? null : (str_starts_with($path, 'http') ? $path : Storage::disk('public')->url($path));
    }

    public function coverUrl(): ?string
    {
        return self::imageUrl($this->cover_image);
    }

    /** @return list<string> */
    public function photoUrls(): array
    {
        return array_values(array_filter(array_map([self::class, 'imageUrl'], (array) ($this->photos ?? []))));
    }

    /** Settings scope (zone → franchise → city → country). */
    public function scope(): array
    {
        return array_filter([
            'zone_id' => $this->zone_id, 'franchise_id' => $this->franchise_id,
            'city_id' => $this->franchise?->city_id, 'country_id' => $this->franchise?->country_id,
        ]);
    }

    /** "2 BHK flat / apartment for rent in Magunta Layout" — made from the owner's taps, never typed. */
    public static function makeTitle(?string $size, ?string $type, ?string $area): string
    {
        $what = trim(implode(' ', array_filter([$size, $type ? mb_strtolower($type) : null]))) ?: 'Home';

        return Str::limit(Str::ucfirst($what).' for rent'.($area ? ' in '.$area : ''), 150, '');
    }

    /** A clean, readable slug; -2, -3 only on a clash (public link rule). */
    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug(Str::limit($title, 150, '')) ?: 'home';
        $slug = $base;
        for ($i = 2; static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
