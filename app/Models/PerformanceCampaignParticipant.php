<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerformanceCampaignParticipant extends Model
{
    protected $table = 'performance_campaign_participants';

    protected $fillable = [
        'performance_campaign_id', 'participant_type', 'participant_id',
        'metric_value', 'rank', 'qualified', 'disqualified_reason',
        'reward_status', 'reward_amount', 'reward_ref',
    ];

    protected $casts = [
        'metric_value' => 'decimal:2',
        'reward_amount' => 'decimal:2',
        'qualified' => 'boolean',
    ];

    public function campaign() { return $this->belongsTo(PerformanceCampaign::class, 'performance_campaign_id'); }

    /** The real Franchise/Provider/FieldWorker/User row this progress row tracks. */
    public function participant() { return $this->morphTo(); }
}
