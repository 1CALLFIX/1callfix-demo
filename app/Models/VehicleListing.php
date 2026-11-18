<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Vehicle for rent (owner 2026-11-18, OLX-style): a car, bike, auto, van, truck or tractor an owner lets by the day or
 * hour. Owned by a Provider account (the one partner sign-in). Customers contact the owner; no booking or payment runs
 * between them. The platform earns the listing fee.
 *
 * Status: draft → in_review → approved | rejected ("Needs changes", editable again). Once approved, prices, features and
 * rules change at once; photos, description and the pickup address wait in `pending_changes` for the team (same rule
 * as homes and halls). The owner can switch it off ("Not available now") and on again within the listing period.
 */
class VehicleListing extends Model
{
    public const STATUS_LABELS = [
        'draft' => 'Draft', 'in_review' => 'Waiting for review', 'approved' => 'Approved', 'rejected' => 'Needs changes',
    ];

    /** Fields of a live listing that wait for the team before customers see them. */
    public const REVIEWED = ['about', 'address_line', 'landmark', 'lat', 'lng', 'cover_image', 'photos'];

    public const MAX_PHOTOS = 12;

    protected $fillable = [
        'provider_id', 'franchise_id', 'zone_id', 'title', 'slug', 'vehicle_kind', 'make', 'model', 'year', 'fuel',
        'transmission', 'seats', 'driver_options', 'price_day', 'price_hour', 'deposit', 'km_per_day', 'features',
        'rule_list', 'about', 'landmark', 'address_line', 'lat', 'lng', 'cover_image', 'photos', 'contact_phone', 'status',
        'rejection_reason', 'submitted_at', 'approved_at', 'is_active', 'is_featured', 'featured_until', 'expires_at',
        'pending_changes', 'pending_requested_at', 'pending_rejection',
    ];

    protected $casts = [
        'driver_options' => 'array',
        'features' => 'array',
        'rule_list' => 'array',
        'photos' => 'array',
        'pending_changes' => 'array',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'price_day' => 'decimal:2',
        'price_hour' => 'decimal:2',
        'deposit' => 'decimal:2',
        'year' => 'integer',
        'seats' => 'integer',
        'km_per_day' => 'integer',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'featured_until' => 'datetime',
        'expires_at' => 'datetime',
        'pending_requested_at' => 'datetime',
    ];

    public function provider() { return $this->belongsTo(Provider::class); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }

    /** Customers see it: approved, switched on, not expired. */
    public function isLive(): bool
    {
        return $this->status === 'approved' && $this->is_active
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

    /** "Maruti Swift car for rent in Magunta Layout" — made from the owner's taps, never typed. */
    public static function makeTitle(?string $kind, ?string $make, ?string $model, ?string $area): string
    {
        $name = trim(implode(' ', array_filter([$make, $model])));
        $what = trim(implode(' ', array_filter([$name, $kind ? mb_strtolower($kind) : null]))) ?: 'Vehicle';

        return Str::limit(Str::ucfirst($what).' for rent'.($area ? ' in '.$area : ''), 150, '');
    }

    /** A clean, readable slug; -2, -3 only on a clash (public link rule). */
    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug(Str::limit($title, 150, '')) ?: 'vehicle';
        $slug = $base;
        for ($i = 2; static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
