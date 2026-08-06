<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
