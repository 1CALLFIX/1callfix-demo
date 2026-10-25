<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * E-commerce Phase 0 — how one completed marketplace order's money is split and when it is paid. See
 * MarketplaceSettlementService.
 */
class MarketplaceSettlement extends Model
{
    protected $fillable = [
        'marketplace_order_id', 'commission_id', 'provider_id', 'field_worker_id', 'payment_mode',
        'goods_amount', 'seller_share', 'goods_platform', 'goods_franchise',
        'delivery_amount', 'rider_share', 'delivery_platform', 'delivery_franchise', 'rider_topup', 'rider_topup_funded_by',
        'release_at', 'released_at', 'status',
        'cash_debtor_type', 'cash_owed', 'cash_franchise_portion', 'cash_settled', 'cash_franchise_settled',
        'cash_status', 'cash_settled_at',
    ];

    protected $casts = [
        'goods_amount' => 'decimal:2',
        'seller_share' => 'decimal:2',
        'goods_platform' => 'decimal:2',
        'goods_franchise' => 'decimal:2',
        'delivery_amount' => 'decimal:2',
        'rider_share' => 'decimal:2',
        'delivery_platform' => 'decimal:2',
        'delivery_franchise' => 'decimal:2',
        'rider_topup' => 'decimal:2',
        'cash_owed' => 'decimal:2',
        'cash_franchise_portion' => 'decimal:2',
        'cash_settled' => 'decimal:2',
        'cash_franchise_settled' => 'decimal:2',
        'release_at' => 'datetime',
        'released_at' => 'datetime',
        'cash_settled_at' => 'datetime',
    ];

    public function marketplaceOrder() { return $this->belongsTo(MarketplaceOrder::class); }
    public function commission() { return $this->belongsTo(Commission::class); }
    public function provider() { return $this->belongsTo(Provider::class); }
    public function fieldWorker() { return $this->belongsTo(FieldWorker::class); }

    public function cashOutstanding(): float
    {
        return round((float) $this->cash_owed - (float) $this->cash_settled, 2);
    }

    public function scopeCashOutstandingFor(Builder $query, string $debtorType, int $debtorId): Builder
    {
        return $query->where('cash_status', 'outstanding')
            ->where('cash_debtor_type', $debtorType)
            ->where($debtorType === 'provider' ? 'provider_id' : 'field_worker_id', $debtorId);
    }
}
