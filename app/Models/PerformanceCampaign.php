<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Performance/Growth Campaign — see the create-table migration's own
 * docblock for how this differs from NotificationCampaign. This is the
 * incentive-tracking definition; PerformanceCampaignParticipant is the
 * per-actor progress/reward row; PerformanceCampaignService is the only
 * place lifecycle transitions and reward disbursement happen.
 */
class PerformanceCampaign extends Model
{
    use HasFactory;

    protected $table = 'performance_campaigns';

    protected $fillable = [
        'name', 'description', 'audience_type', 'scope_type', 'scope_id',
        'metric_key', 'starts_at', 'ends_at', 'qualification_mode',
        'target_value', 'top_n', 'reward_type', 'reward_value', 'badge_id',
        'requires_approval', 'status', 'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'target_value' => 'decimal:2',
        'reward_value' => 'decimal:2',
        'requires_approval' => 'boolean',
    ];

    public const TERMINAL_STATUSES = ['closed', 'cancelled'];

    public function badge() { return $this->belongsTo(Badge::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function participants() { return $this->hasMany(PerformanceCampaignParticipant::class); }

    /** Ancestor-inclusive scope hint for AuthorizationService::can()/scopeQuery()/scopeCovers() — same pattern as Plan/Badge/FlashSale. */
    public function authorizationScopeHint(): array
    {
        return app(\App\Services\AuthorizationService::class)->ancestryFor($this->scope_type, $this->scope_id);
    }

    /**
     * The concrete Eloquent class backing this campaign's audience_type —
     * used by CampaignMetricResolver/PerformanceCampaignService to resolve
     * real participant rows rather than trusting a caller-supplied type
     * string (closes an IDOR/type-confusion path: a participant_type is
     * always derived from the campaign, never accepted from a request).
     */
    public function participantModelClass(): string
    {
        return match ($this->audience_type) {
            'franchise' => Franchise::class,
            'provider' => Provider::class,
            'field_worker' => FieldWorker::class,
            'customer' => User::class,
        };
    }

    /**
     * Who actually receives a wallet/points/badge reward for this
     * participant. Franchise and Provider/FieldWorker (via user_id) don't
     * carry their own wallet — the only wallet reachable from those rows is
     * the linked User's. This is a structural mapping forced by the
     * existing schema (WalletService::credit() takes a User), not a
     * commercial decision.
     */
    public function rewardRecipientFor(Model $participant): ?User
    {
        return match ($this->audience_type) {
            'franchise' => $participant->owner ?? User::find($participant->owner_user_id),
            'provider', 'field_worker' => $participant->user ?? User::find($participant->user_id),
            'customer' => $participant,
            default => null,
        };
    }
}
