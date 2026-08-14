<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A purchased plan instance, any actor — subscribable_type/id is
 * polymorphic (App\Models\User for Customer Prime / Provider Package,
 * App\Models\BusinessAccount for a pooled business subscription). See
 * SubscriptionService for the full lifecycle this status field moves through.
 */
class Subscription extends Model
{
    use HasFactory;

    protected $table = 'subscriptions';

    protected $fillable = [
        'subscribable_type', 'subscribable_id', 'plan_id', 'status',
        'starts_at', 'current_period_start', 'current_period_end', 'expires_at',
        'auto_renew', 'cancelled_at', 'cancellation_reason', 'grace_period_ends_at',
        'pending_plan_id', 'pending_change_type', 'pending_change_effective_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'expires_at' => 'datetime',
        'auto_renew' => 'boolean',
        'cancelled_at' => 'datetime',
        'grace_period_ends_at' => 'datetime',
        'pending_change_effective_at' => 'datetime',
    ];

    public function subscribable() { return $this->morphTo(); }
    public function plan() { return $this->belongsTo(Plan::class); }
    public function pendingPlan() { return $this->belongsTo(Plan::class, 'pending_plan_id'); }
    public function entitlementBalances() { return $this->hasMany(EntitlementBalance::class); }
    public function usageLedger() { return $this->hasMany(UsageLedger::class); }

    public function isUsable(): bool
    {
        return in_array($this->status, ['active', 'grace_period'], true);
    }

    /**
     * A subscription has no geography of its own -- visibility follows its
     * PLAN's, the same basis Subscriptions\Index's own pause/resume/cancel/
     * renewNow/confirmAdjust mutation checks already use (previously computed
     * inline in that component as scopeHint(); relocated here, unchanged,
     * so AuthorizationService::visibleAmong() can reuse it for viewing too).
     */
    public function authorizationScopeHint(): array
    {
        return $this->plan?->authorizationScopeHint() ?? [];
    }
}
