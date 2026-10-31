<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Parcel Stage 2 — a customer's bought parcel pack: a snapshot of the pack plus how many credits are used. */
class ParcelPackPurchase extends Model
{
    protected $fillable = [
        'parcel_pack_id', 'user_id', 'franchise_id', 'name', 'parcels_total', 'parcels_used', 'max_km', 'price_paid',
        'validity_days', 'status', 'payment_method', 'paid_at', 'expires_at',
    ];

    protected $casts = [
        'parcels_total' => 'integer', 'parcels_used' => 'integer', 'max_km' => 'decimal:2', 'price_paid' => 'decimal:2',
        'validity_days' => 'integer', 'paid_at' => 'datetime', 'expires_at' => 'datetime',
    ];

    public function pack() { return $this->belongsTo(ParcelPack::class, 'parcel_pack_id'); }
    public function user() { return $this->belongsTo(User::class); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function payments() { return $this->hasMany(Payment::class); }

    public function remaining(): int
    {
        return max(0, $this->parcels_total - $this->parcels_used);
    }

    public function isUsable(): bool
    {
        return $this->status === 'active' && $this->expires_at?->isFuture() && $this->remaining() > 0;
    }

    /** Active, unexpired, with credits left — usable for a parcel picked up in $franchiseId. */
    public function scopeUsableIn(Builder $query, int $userId, ?int $franchiseId): Builder
    {
        return $query->where('user_id', $userId)->where('status', 'active')->where('expires_at', '>', now())
            ->whereColumn('parcels_used', '<', 'parcels_total')
            ->where(fn ($q) => $q->whereNull('franchise_id')->orWhere('franchise_id', $franchiseId));
    }
}
