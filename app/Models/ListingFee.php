<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One listing fee an owner paid (or started to pay): `kind` listing = goes live / renews, featured = shown first.
 * listing_type "item" (people's posts, 2026-11-19): kind featured | boost | bulk_small | bulk_large (PostPackService),
 * method credit = a Featured used from a bulk pack (amount 0).
 */
class ListingFee extends Model
{
    protected $fillable = [
        'listing_type', 'listing_id', 'user_id', 'franchise_id', 'kind', 'amount', 'franchise_share', 'days', 'credits', 'credits_used', 'credits_until', 'method', 'status',
        'gateway', 'gateway_order_id', 'gateway_payment_id', 'paid_at',
    ];

    protected $casts = ['amount' => 'decimal:2', 'franchise_share' => 'decimal:2', 'credits' => 'integer', 'credits_used' => 'integer', 'credits_until' => 'datetime', 'paid_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
}
