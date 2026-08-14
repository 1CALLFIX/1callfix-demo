<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationCampaign extends Model
{
    protected $table = 'notification_campaigns';

    protected $fillable = [
        'category', 'type', 'title', 'message', 'image_url', 'action_url', 'module',
        'recipient_type', 'specific_user_id', 'scope_type', 'scope_id', 'filters',
        'channels', 'priority', 'coupon_id', 'template_id', 'meeting_id',
        'status', 'scheduled_at', 'expires_at', 'sent_at', 'recipient_count', 'created_by',
    ];

    protected $casts = [
        'filters' => 'array',
        'scheduled_at' => 'datetime',
        'expires_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function coupon() { return $this->belongsTo(Coupon::class); }
    public function template() { return $this->belongsTo(NotificationTemplate::class, 'template_id'); }
    public function meeting() { return $this->belongsTo(NotificationMeeting::class, 'meeting_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function specificUser() { return $this->belongsTo(User::class, 'specific_user_id'); }
    public function logs() { return $this->hasMany(NotificationLog::class, 'campaign_id'); }

    /** Ancestor-inclusive scope hint for AuthorizationService::can()/scopeQuery() — same pattern as Plan::authorizationScopeHint(), shared via AuthorizationService::ancestryFor() rather than reimplemented. */
    public function authorizationScopeHint(): array
    {
        return app(\App\Services\AuthorizationService::class)->ancestryFor($this->scope_type, $this->scope_id);
    }

    /** Same scope-hint shape AudienceResolver/Setting::get() expect. */
    public function audienceSpec(): array
    {
        return [
            'recipient_type' => $this->recipient_type,
            'specific_user_id' => $this->specific_user_id,
            'scope_type' => $this->scope_type,
            'scope_id' => $this->scope_id,
            'filters' => $this->filters ?? [],
        ];
    }

    public function channelList(): array
    {
        return array_filter(explode(',', $this->channels));
    }
}
