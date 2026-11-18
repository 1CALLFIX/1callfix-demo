<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Rent anything (owner decision 2026-11-18, OLX-style): an item a person rents out (camera, speaker, furniture, tools,
 * party items...). Posted from their own account by anyone with a verified mobile number; no partner sign-up.
 *
 * Status: draft → in_review → approved | rejected ("Needs changes", editable again). The franchise team approves each
 * post. The owner can switch an approved item off ("Not available now") and on again within the listing period.
 * Enough open reports from customers hide it (hidden_for_reports_at) until the team checks it.
 */
class RentalItem extends Model
{
    public const STATUS_LABELS = [
        'draft' => 'Draft', 'in_review' => 'Waiting for review', 'approved' => 'Approved', 'rejected' => 'Needs changes',
    ];

    public const MAX_PHOTOS = 8;

    protected $attributes = ['status' => 'draft', 'is_active' => false, 'is_featured' => false, 'can_deliver' => false, 'open_reports' => 0];

    protected $fillable = [
        'user_id', 'franchise_id', 'zone_id', 'name', 'title', 'slug', 'category', 'condition', 'price', 'price_unit',
        'deposit', 'can_deliver', 'about', 'landmark', 'cover_image', 'photos', 'status', 'rejection_reason',
        'submitted_at', 'approved_at', 'approved_by', 'is_active', 'is_featured', 'featured_until', 'expires_at',
        'open_reports', 'hidden_for_reports_at',
    ];

    protected $casts = [
        'photos' => 'array',
        'can_deliver' => 'boolean',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'price' => 'decimal:2',
        'deposit' => 'decimal:2',
        'open_reports' => 'integer',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'featured_until' => 'datetime',
        'expires_at' => 'datetime',
        'hidden_for_reports_at' => 'datetime',
    ];

    public function user() { return $this->belongsTo(User::class); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function reports() { return $this->hasMany(RentalItemReport::class); }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }

    /** Customers see it: approved, switched on, not expired, not hidden by reports. */
    public function isLive(): bool
    {
        return $this->status === 'approved' && $this->is_active && $this->hidden_for_reports_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** Counts toward the owner's limit of items: everything not a draft and not sent back. */
    public function countsTowardLimit(): bool
    {
        return in_array($this->status, ['in_review', 'approved'], true) && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
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

    /** "Sony A7 camera for rent in Magunta Layout": the owner types only the name; the rest is made for them. */
    public static function makeTitle(string $name, ?string $area): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name)) ?: 'Item';

        return Str::limit(Str::ucfirst($name).' for rent'.($area ? ' in '.$area : ''), 150, '');
    }

    /** A clean, readable slug; -2, -3 only on a clash (public link rule). */
    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug(Str::limit($title, 150, '')) ?: 'item';
        $slug = $base;
        for ($i = 2; static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
