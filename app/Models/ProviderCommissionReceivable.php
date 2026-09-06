<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * What a provider owes the platform (+ its franchise) on a cash-paid
 * Service booking, because the customer's money went straight to the
 * provider and never through the gateway. Recovered by wallet debit at
 * payout-request time; see PayoutService::settleCashCommissionReceivables().
 */
class ProviderCommissionReceivable extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_id', 'booking_id', 'commission_id',
        'platform_portion', 'franchise_portion',
        'amount_owed', 'amount_settled', 'status', 'settled_at',
    ];

    protected $casts = [
        'platform_portion' => 'decimal:2',
        'franchise_portion' => 'decimal:2',
        'amount_owed' => 'decimal:2',
        'amount_settled' => 'decimal:2',
        'settled_at' => 'datetime',
    ];

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function commission()
    {
        return $this->belongsTo(Commission::class);
    }

    /** Still-uncollected amount on this row. */
    public function outstandingAmount(): float
    {
        return round((float) $this->amount_owed - (float) $this->amount_settled, 2);
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('status', 'outstanding');
    }
}
