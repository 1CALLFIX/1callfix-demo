<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Banner extends Model
{
    use HasFactory;

    protected $table = 'banners';

    protected $fillable = [
        'franchise_id',
        'zone_id',
        'title',
        'image',
        'link',
        'starts_at',
        'expires_at',
        'advertiser_name',
        'advertiser_contact',
        'price_paid',
        'sort_order',
        'is_active'
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }

    /**
     * Uploaded banners hold a path on the `public` disk; anything seeded or
     * imported may hold a full URL. Resolve both — same contract as
     * ServiceCategory::image_url.
     */
    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image) {
            return null;
        }

        if (Str::startsWith($this->image, ['http://', 'https://', '//', 'data:'])) {
            return $this->image;
        }

        return Storage::disk('public')->url($this->image);
    }

    /**
     * Where a banner sits in its lifecycle, for the admin list badge:
     * inactive (switched off) > scheduled (window hasn't opened) >
     * expired (window closed) > live. Deliberately mirrors scopeCurrentlyLive
     * so the badge and the "live" filter can never disagree.
     */
    public function getStatusAttribute(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return 'scheduled';
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return 'expired';
        }

        return 'live';
    }

    /** True for a sold ad slot, false for a house/own-promo banner. */
    public function getIsPaidAttribute(): bool
    {
        return $this->price_paid !== null;
    }

    /** Human placement summary: zone beats franchise beats everywhere. */
    public function getPlacementAttribute(): string
    {
        if ($this->zone) {
            return $this->zone->display_name;
        }

        if ($this->franchise) {
            return $this->franchise->name;
        }

        return 'All franchises';
    }

    /**
     * Banners that should actually be shown right now: active flag on,
     * and (if dates are set) within the paid window. Banners with no
     * starts_at/expires_at are treated as always-on (house banners).
     */
    public function scopeCurrentlyLive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            });
    }

    /**
     * Paid ad slots expiring within the next N days — useful for a renewal
     * reminder list so revenue doesn't quietly lapse unnoticed.
     */
    public function scopeExpiringSoon($query, int $days = 7)
    {
        return $query->whereNotNull('price_paid')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($days)]);
    }
}
