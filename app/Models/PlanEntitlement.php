<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlanEntitlement extends Model
{
    use HasFactory;

    protected $table = 'plan_entitlements';

    protected $fillable = [
        'plan_id', 'entitlement_type', 'module', 'quantity', 'monetary_value',
        'percentage_value', 'usage_period', 'consumption_trigger', 'rollover_policy',
        'rollover_cap', 'rollover_expiry_days', 'overage_enabled', 'overage_rate_type',
        'overage_rate_value', 'requires_approval', 'is_approved',
    ];

    protected $casts = [
        'overage_enabled' => 'boolean',
        'requires_approval' => 'boolean',
        'is_approved' => 'boolean',
        'monetary_value' => 'decimal:2',
        'percentage_value' => 'decimal:2',
        'overage_rate_value' => 'decimal:2',
    ];

    public function plan() { return $this->belongsTo(Plan::class); }

    /**
     * commission_override is unusable unless BOTH requires_approval was
     * turned on for this entitlement AND an admin has actually approved it —
     * amendment 21's "no finalized commercial numbers, no silent go-live for
     * a rate override" concern, enforced here rather than trusted to the
     * admin form alone.
     */
    public function isUsable(): bool
    {
        if ($this->entitlement_type === 'commission_override') {
            return $this->requires_approval && $this->is_approved;
        }

        return true;
    }
}
