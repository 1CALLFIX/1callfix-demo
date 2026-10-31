<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Parcel Stage 2 — a prepaid parcel pack Super Admin sells to businesses (owner decision 2026-10-31). */
class ParcelPack extends Model
{
    protected $fillable = ['name', 'franchise_id', 'parcel_count', 'max_km', 'price', 'validity_days', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected $casts = [
        'parcel_count' => 'integer', 'max_km' => 'decimal:2', 'price' => 'decimal:2',
        'validity_days' => 'integer', 'is_active' => 'boolean',
    ];

    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function purchases() { return $this->hasMany(ParcelPackPurchase::class); }
}
