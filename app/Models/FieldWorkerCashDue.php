<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What a rider owes after collecting cash for a parcel: the platform + franchise share of the price. The
 * FieldWorker counterpart of ProviderCommissionReceivable (same columns, same oldest-first recovery from the
 * wallet, same proportional franchise payout) — see FieldWorkerCashDueService.
 */
class FieldWorkerCashDue extends Model
{
    protected $fillable = [
        'field_worker_id', 'parcel_order_id', 'commission_id',
        'platform_portion', 'franchise_portion',
        'amount_owed', 'amount_settled', 'franchise_settled', 'status', 'settled_at',
    ];

    protected $casts = [
        'platform_portion' => 'decimal:2',
        'franchise_portion' => 'decimal:2',
        'amount_owed' => 'decimal:2',
        'amount_settled' => 'decimal:2',
        'franchise_settled' => 'decimal:2',
        'settled_at' => 'datetime',
    ];

    public function fieldWorker() { return $this->belongsTo(FieldWorker::class); }
    public function parcelOrder() { return $this->belongsTo(ParcelOrder::class); }
    public function commission() { return $this->belongsTo(Commission::class); }

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
