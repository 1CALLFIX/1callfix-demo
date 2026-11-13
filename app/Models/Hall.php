<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Function halls R1 (owner 2026-11-13): a hall an owner lists for weddings, receptions, birthdays and meetings.
 * Owned by a Provider account (the partner sign-in, documents and payouts every partner already uses).
 *
 * Status: draft → in_review → approved | rejected ("Needs changes", editable again). Once approved the owner keeps
 * changing prices, slots, deposit, rules, capacity and blocked dates at once; a change to the name, address, map pin,
 * photos or description waits in `pending_changes` for the team while the hall stays bookable with the old details
 * (same rule as shops, owner 2026-11-03).
 */
class Hall extends Model
{
    public const STATUS_LABELS = [
        'draft' => 'Draft', 'in_review' => 'Waiting for review', 'approved' => 'Approved', 'rejected' => 'Needs changes',
    ];

    /** Fields of a live hall that wait for the team before customers see them. */
    public const REVIEWED = ['name', 'about', 'address_line', 'lat', 'lng', 'cover_image', 'photos'];

    public const MAX_PHOTOS = 10;

    protected $fillable = [
        'provider_id', 'franchise_id', 'zone_id', 'name', 'slug', 'hall_type', 'about', 'address_line', 'lat', 'lng',
        'contact_phone', 'seating_capacity', 'floating_capacity', 'is_ac', 'parking_cars', 'rooms', 'amenities',
        'cover_image', 'photos', 'rules', 'deposit_amount', 'gst_number', 'status', 'rejection_reason', 'submitted_at',
        'approved_at', 'is_active', 'is_featured', 'pending_changes', 'pending_requested_at', 'pending_rejection',
    ];

    protected $casts = [
        'amenities' => 'array',
        'photos' => 'array',
        'pending_changes' => 'array',
        'is_ac' => 'boolean',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'pending_requested_at' => 'datetime',
        'deposit_amount' => 'decimal:2',
        'seating_capacity' => 'integer',
        'floating_capacity' => 'integer',
        'parking_cars' => 'integer',
        'rooms' => 'integer',
    ];

    public function provider() { return $this->belongsTo(Provider::class); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function slots() { return $this->hasMany(HallSlot::class)->orderBy('sort_order')->orderBy('id'); }
    public function blockedDates() { return $this->hasMany(HallBlockedDate::class)->orderBy('date'); }

    /** The owner may change everything: not yet sent, or sent back. */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }

    public function isLive(): bool
    {
        return $this->status === 'approved' && $this->is_active;
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

    /** Lowest slot price, for "From ₹…" in lists. */
    public function fromPrice(): ?float
    {
        $min = $this->slots->where('is_active', true)->min('price');

        return $min === null ? null : (float) $min;
    }

    /** Settings scope (zone → franchise → city → country). */
    public function scope(): array
    {
        return array_filter([
            'zone_id' => $this->zone_id, 'franchise_id' => $this->franchise_id,
            'city_id' => $this->franchise?->city_id, 'country_id' => $this->franchise?->country_id,
        ]);
    }

    /** A clean, readable slug; -2, -3 only on a clash (public link rule). */
    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug(Str::limit($name, 150, '')) ?: 'hall';
        $slug = $base;
        for ($i = 2; static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
