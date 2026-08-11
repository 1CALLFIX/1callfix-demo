<?php

namespace App\Services;

use App\Models\NotificationCampaign;
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
        $recipients = $this->audienceResolver->resolve($campaign->audienceSpec())->get();

        foreach ($recipients as $recipient) {
            $recipient->notify(new CampaignNotification($campaign, $channels));
        }

        $campaign->update([
            'status' => 'sent',
            'sent_at' => now(),
            'recipient_count' => $recipients->count(),
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
}
