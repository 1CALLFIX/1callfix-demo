<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only. Rows are never updated (there is no updated_at column —
 * see the migration). Every consume/reverse/adjust/expire/rollover event
 * this app performs against a plan entitlement is one row here, forever.
 */
class UsageLedger extends Model
{
    use HasFactory;

    protected $table = 'usage_ledger';

    const UPDATED_AT = null;

    protected $fillable = [
        'subscription_id', 'plan_entitlement_id', 'entitlement_balance_id', 'booking_id',
        'event_type', 'quantity_delta', 'monetary_delta', 'was_overage',
        'overage_amount_charged', 'reason', 'related_usage_ledger_id', 'created_by',
    ];

    protected $casts = [
        'was_overage' => 'boolean',
        'monetary_delta' => 'decimal:2',
        'overage_amount_charged' => 'decimal:2',
    ];

    public function subscription() { return $this->belongsTo(Subscription::class); }
    public function planEntitlement() { return $this->belongsTo(PlanEntitlement::class); }
    public function entitlementBalance() { return $this->belongsTo(EntitlementBalance::class); }
    public function booking() { return $this->belongsTo(Booking::class); }
    public function related() { return $this->belongsTo(UsageLedger::class, 'related_usage_ledger_id'); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
}
