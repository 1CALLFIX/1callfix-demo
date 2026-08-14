<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationMeeting extends Model
{
    protected $table = 'notification_meetings';

    protected $fillable = [
        'title', 'description', 'starts_at', 'duration_minutes', 'location', 'meeting_link',
        'recipient_type', 'specific_user_id', 'scope_type', 'scope_id', 'module',
        'organizer_user_id', 'reminder_offsets_minutes', 'status',
    ];

    protected $casts = ['starts_at' => 'datetime', 'reminder_offsets_minutes' => 'array'];

    public function organizer() { return $this->belongsTo(User::class, 'organizer_user_id'); }
    public function specificUser() { return $this->belongsTo(User::class, 'specific_user_id'); }
    public function campaigns() { return $this->hasMany(NotificationCampaign::class, 'meeting_id'); }

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
            'filters' => [],
        ];
    }
}
