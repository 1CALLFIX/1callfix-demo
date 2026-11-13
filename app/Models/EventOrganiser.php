<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Events E1 (owner 2026-11-13): the organiser business a partner signs up as (a DJ, club, college, hotel...). Waits at
 * approval_status 'pending', switched off, until an admin approves it in Admin → Events. Owned by a Provider account,
 * so payouts, documents and the partner sign-in are the ones every partner already uses.
 */
class EventOrganiser extends Model
{
    protected $fillable = [
        'provider_id', 'franchise_id', 'zone_id', 'name', 'about', 'contact_phone', 'address_line', 'gst_number', 'logo',
        'approval_status', 'rejection_reason', 'approved_at', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'approved_at' => 'datetime',
    ];

    public function provider() { return $this->belongsTo(Provider::class); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function events() { return $this->hasMany(Event::class); }

    public function isApproved(): bool
    {
        return $this->approval_status === 'approved';
    }

    /** Settings scope (zone → franchise → city → country). */
    public function scope(): array
    {
        return array_filter([
            'zone_id' => $this->zone_id, 'franchise_id' => $this->franchise_id,
            'city_id' => $this->franchise?->city_id, 'country_id' => $this->franchise?->country_id,
        ]);
    }
}
