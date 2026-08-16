<?php

namespace App\Services;

use App\Models\NotificationCampaign;
use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\CampaignNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;

/**
 * The Notification Center's send path: Campaign -> AudienceResolver ->
 * recipients -> the SAME Notification/Channel/Adapter pipeline every
 * transactional notification already uses (CampaignNotification, not a
 * second delivery mechanism). See CampaignNotification's docblock.
 */
class CampaignService
{
    public function __construct(private AudienceResolver $audienceResolver)
    {
    }

    public function previewRecipientCount(array $spec): int
    {
        return $this->audienceResolver->resolve($spec)->count();
    }

    /**
     * Sends immediately. A scheduled campaign becomes 'sent' the same way,
     * just invoked later by DispatchDueCampaigns instead of the composer.
     */
    public function send(NotificationCampaign $campaign): NotificationCampaign
    {
        if (in_array($campaign->status, ['sent', 'cancelled'], true)) {
            throw new \RuntimeException("Campaign #{$campaign->id} is already {$campaign->status}.");
        }

        if ($campaign->expires_at && $campaign->expires_at->isPast()) {
            $campaign->update(['status' => 'cancelled']);
            throw new \RuntimeException("Campaign #{$campaign->id} expired before it was sent.");
        }

        $campaign->update(['status' => 'sending']);

        $channels = ChannelResolver::mapChannels($campaign->channelList());

        // Phase 21 item TECH-7: chunked instead of one ->get(), matching
        // KycReminderService::dispatchDue()'s own established pattern --
        // a large audience no longer has to be fully hydrated into memory
        // before the first notify() call. CampaignNotification already
        // implements ShouldQueue (Phase 18), so this loop itself was never
        // the timeout risk; only the audience hydration was.
        $recipientCount = 0;
        $this->audienceResolver->resolve($campaign->audienceSpec())
            ->chunkById(200, function ($recipients) use ($campaign, $channels, &$recipientCount) {
                foreach ($recipients as $recipient) {
                    $recipient->notify(new CampaignNotification($campaign, $channels));
                }
                $recipientCount += $recipients->count();
            });

        $campaign->update([
            'status' => 'sent',
            'sent_at' => now(),
            'recipient_count' => $recipientCount,
        ]);

        return $campaign->fresh();
    }

    public function schedule(NotificationCampaign $campaign, \DateTimeInterface $when): NotificationCampaign
    {
        $campaign->update(['status' => 'scheduled', 'scheduled_at' => $when]);

        return $campaign->fresh();
    }

    /**
     * Called by the DispatchDueCampaigns scheduled command — every campaign
     * whose scheduled_at has arrived and hasn't already been sent/cancelled.
     */
    public function sendAllDue(): int
    {
        $sent = 0;

        NotificationCampaign::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->each(function (NotificationCampaign $campaign) use (&$sent) {
                try {
                    $this->send($campaign);
                    $sent++;
                } catch (\Throwable $e) {
                    $campaign->update(['status' => 'failed']);
                }
            });

        return $sent;
    }

    /**
     * Real, working retry (mission Phase 8 -- "delivery logs, failures,
     * retry"), not a placeholder button: re-sends this campaign's exact
     * notification to every recipient whose MOST RECENT notification_logs
     * row for this campaign is 'failed' -- not "ever failed", so calling
     * this twice in a row never re-notifies someone whose retry already
     * succeeded (the second call finds their latest row is now 'sent').
     * Only meaningful for already-sent campaigns; a transactional (non-
     * campaign) notification has no generic retry here -- see this
     * method's own docblock reasoning in the audit notes for why that
     * would require per-notification-type logic this session doesn't
     * invent.
     */
    public function resendToFailedRecipients(NotificationCampaign $campaign): int
    {
        if ($campaign->status !== 'sent') {
            throw new \RuntimeException("Campaign #{$campaign->id} must be 'sent' before it can be resent to failed recipients.");
        }

        $latestLogIdPerRecipient = NotificationLog::where('campaign_id', $campaign->id)
            ->where('notifiable_type', User::class)
            ->selectRaw('MAX(id) as id')
            ->groupBy('notifiable_id')
            ->pluck('id');

        $failedUserIds = NotificationLog::whereIn('id', $latestLogIdPerRecipient)
            ->where('status', 'failed')
            ->pluck('notifiable_id');

        if ($failedUserIds->isEmpty()) {
            return 0;
        }

        $channels = ChannelResolver::mapChannels($campaign->channelList());

        // Same chunking as send() above (Phase 21 item TECH-7) -- a failed
        // recipient list can be just as large as the original audience.
        $resentCount = 0;
        User::whereIn('id', $failedUserIds)
            ->chunkById(200, function ($recipients) use ($campaign, $channels, &$resentCount) {
                foreach ($recipients as $recipient) {
                    $recipient->notify(new CampaignNotification($campaign, $channels));
                }
                $resentCount += $recipients->count();
            });

        return $resentCount;
    }
}
