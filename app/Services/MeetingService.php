<?php

namespace App\Services;

use App\Models\NotificationCampaign;
use App\Models\NotificationMeeting;
use Illuminate\Support\Facades\DB;

/**
 * A meeting is a business object, not a notification — creating one
 * produces an immediate "announcement" Campaign plus one scheduled
 * "reminder" Campaign per configured offset, each going through the exact
 * same CampaignService::send()/sendAllDue() path as any other campaign
 * (meetings just decide WHEN and HOW MANY campaign rows to create).
 */
class MeetingService
{
    public function __construct(private CampaignService $campaignService)
    {
    }

    public function scheduleMeeting(NotificationMeeting $meeting): array
    {
        return DB::transaction(function () use ($meeting) {
            $campaigns = [];

            $announcement = NotificationCampaign::create([
                'category' => 'operations',
                'type' => 'meeting_announcement',
                'title' => $meeting->title,
                'message' => $this->describeMeeting($meeting),
                'action_url' => $meeting->meeting_link,
                'module' => $meeting->module,
                'recipient_type' => $meeting->recipient_type,
                'specific_user_id' => $meeting->specific_user_id,
                'scope_type' => $meeting->scope_type,
                'scope_id' => $meeting->scope_id,
                'channels' => 'mail,sms,push,in_app',
                'meeting_id' => $meeting->id,
                'status' => 'draft',
                'created_by' => $meeting->organizer_user_id,
            ]);
            $campaigns[] = $this->campaignService->send($announcement);

            foreach ($meeting->reminder_offsets_minutes ?? [] as $offsetMinutes) {
                $reminderAt = $meeting->starts_at->copy()->subMinutes((int) $offsetMinutes);

                if ($reminderAt->isPast()) {
                    continue; // meeting is too soon for this reminder to make sense
                }

                $reminder = NotificationCampaign::create([
                    'category' => 'operations',
                    'type' => 'meeting_reminder',
                    'title' => "Reminder: {$meeting->title}",
                    'message' => $this->describeMeeting($meeting, reminder: true),
                    'action_url' => $meeting->meeting_link,
                    'module' => $meeting->module,
                    'recipient_type' => $meeting->recipient_type,
                    'specific_user_id' => $meeting->specific_user_id,
                    'scope_type' => $meeting->scope_type,
                    'scope_id' => $meeting->scope_id,
                    'channels' => 'mail,sms,push,in_app',
                    'meeting_id' => $meeting->id,
                    'status' => 'draft',
                    'created_by' => $meeting->organizer_user_id,
                ]);
                $campaigns[] = $this->campaignService->schedule($reminder, $reminderAt);
            }

            return $campaigns;
        });
    }

    private function describeMeeting(NotificationMeeting $meeting, bool $reminder = false): string
    {
        $when = $meeting->starts_at->format('D, d M Y \a\t g:i A');
        $where = $meeting->location ?: ($meeting->meeting_link ? 'Online' : 'TBA');
        $lead = $reminder ? 'Upcoming: ' : '';

        return "{$lead}{$meeting->title} — {$when}, {$where}.".($meeting->description ? " {$meeting->description}" : '');
    }
}
