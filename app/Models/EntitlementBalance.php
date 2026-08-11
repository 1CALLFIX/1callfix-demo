<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EntitlementBalance extends Model
{
    use HasFactory;

    protected $table = 'entitlement_balances';

    protected $fillable = [
        'subscription_id', 'plan_entitlement_id', 'period_start', 'period_end',
        'granted_quantity', 'granted_monetary_value', 'rolled_over_quantity',
        'rolled_over_monetary_value', 'rollover_expires_at', 'consumed_quantity',
        'consumed_monetary_value', 'reversed_quantity', 'reversed_monetary_value', 'status',
    ];

    protected $casts = [
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'rollover_expires_at' => 'datetime',
        'granted_monetary_value' => 'decimal:2',
        'rolled_over_monetary_value' => 'decimal:2',
        'consumed_monetary_value' => 'decimal:2',
        'reversed_monetary_value' => 'decimal:2',
    ];

    public function subscription() { return $this->belongsTo(Subscription::class); }
    public function planEntitlement() { return $this->belongsTo(PlanEntitlement::class); }

    /** Net remaining = granted + rolled_over - consumed + reversed. Never mutated directly — only through UsageService. */
    public function remainingQuantity(): int
    {
        return $this->granted_quantity + $this->rolled_over_quantity - $this->consumed_quantity + $this->reversed_quantity;
    }

    public function remainingMonetaryValue(): float
    {
        return round(
            (float) $this->granted_monetary_value + (float) $this->rolled_over_monetary_value
                - (float) $this->consumed_monetary_value + (float) $this->reversed_monetary_value,
            2
        );
    }
}
