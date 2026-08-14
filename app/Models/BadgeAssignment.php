<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One MANUAL badge instance on one entity, in one geographic scope, for one
 * time window — never persisted for 'automatic'-mode badges (see Badge's
 * own docblock). Same scope_type/scope_id shape as Plan/NotificationCampaign,
 * so authorizationScopeHint() reuses the identical
 * AuthorizationService::ancestryFor() this session already extracted for
 * those two, rather than a third re-implementation.
 */
class BadgeAssignment extends Model
{
    use HasFactory;

    protected $table = 'badge_assignments';

    protected $fillable = [
        'badge_id', 'badgeable_type', 'badgeable_id', 'scope_type', 'scope_id',
        'starts_at', 'expires_at', 'is_active', 'assigned_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function badge() { return $this->belongsTo(Badge::class); }
    public function badgeable() { return $this->morphTo(); }
    public function assignedBy() { return $this->belongsTo(User::class, 'assigned_by'); }

    /** Ancestor-inclusive scope hint for AuthorizationService::can()/scopeQuery() — same pattern as Plan::authorizationScopeHint(). */
    public function authorizationScopeHint(): array
    {
        return app(\App\Services\AuthorizationService::class)->ancestryFor($this->scope_type, $this->scope_id);
    }

    /**
     * Active, within its own time window right now — the ONLY query
     * condition "automatic disappearance" needs: no cron has to run to
     * revoke an expired assignment, it simply stops matching this scope
     * the instant expires_at passes.
     */
    public function scopeCurrentlyVisible(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now));
    }
}
