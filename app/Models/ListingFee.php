<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One listing fee an owner paid (or started to pay): `kind` listing = goes live / renews, featured = shown first. */
class ListingFee extends Model
{
    protected $fillable = [
        'listing_type', 'listing_id', 'user_id', 'franchise_id', 'kind', 'amount', 'days', 'method', 'status',
        'gateway', 'gateway_order_id', 'gateway_payment_id', 'paid_at',
    ];

    protected $casts = ['amount' => 'decimal:2', 'paid_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
}
