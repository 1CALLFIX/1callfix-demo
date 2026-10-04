<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The part of a provider's share of a dispute refund that their wallet could not cover when the refund ran.
 * Owed entirely to the company (never split with the franchise). Recovered through the wallet ledger at payout-request
 * time — see PayoutService::settleDisputeDebts().
 */
class ProviderDisputeDebt extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount_owed' => 'decimal:2',
        'amount_settled' => 'decimal:2',
        'settled_at' => 'datetime',
    ];

    public function dispute() { return $this->belongsTo(BookingDispute::class, 'booking_dispute_id'); }

    public function provider() { return $this->belongsTo(Provider::class); }

    public function scopeOutstanding(Builder $q): Builder
    {
        return $q->where('status', 'outstanding');
    }

    public function outstandingAmount(): float
    {
        return round((float) $this->amount_owed - (float) $this->amount_settled, 2);
    }
}
