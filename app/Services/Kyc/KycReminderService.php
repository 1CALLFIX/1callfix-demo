<?php

namespace App\Services\Kyc;

use App\Models\Provider;
use App\Notifications\KycNotification;
use App\Notifications\Support\ChannelResolver;

/**
 * Day 21/27/29/30 reminder milestones (mission's own resolved schedule,
 * relative to kyc_deadline_at = registration + 30 days). Idempotent: each
 * provider's kyc_reminder_stage only ever advances forward, so a daily cron
 * tick never re-sends a milestone already notified, matching
 * ExpireReferrals' own idempotent-command discipline.
 */
class KycReminderService
{
    /** Stage => days-remaining-until-deadline threshold, checked most-urgent-first. */
    private const STAGE_THRESHOLDS = [
        'overdue' => 0,
        'final_warning' => 1,
        'warning' => 3,
        'reminder' => 9,
    ];

    private const STAGE_ORDER = ['reminder', 'warning', 'final_warning', 'overdue'];

    public function dispatchDue(): int
    {
        $sent = 0;

        Provider::whereNotIn('kyc_status', ['approved'])
            ->whereNotNull('kyc_deadline_at')
            ->chunkById(200, function ($providers) use (&$sent) {
                foreach ($providers as $provider) {
                    if ($this->maybeNotify($provider)) {
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    private function maybeNotify(Provider $provider): bool
    {
        $daysRemaining = now()->diffInDays($provider->kyc_deadline_at, false);

        $targetStage = null;
        foreach (self::STAGE_THRESHOLDS as $stage => $threshold) {
            if ($daysRemaining <= $threshold) {
                $targetStage = $stage;
                break;
            }
        }

        if (! $targetStage) {
            return false; // still more than 9 days out — no milestone reached yet
        }

        $currentIndex = $provider->kyc_reminder_stage ? array_search($provider->kyc_reminder_stage, self::STAGE_ORDER, true) : -1;
        $targetIndex = array_search($targetStage, self::STAGE_ORDER, true);

        if ($targetIndex <= $currentIndex) {
            return false; // already notified this stage (or a later one) — never resend
        }

        $event = $targetStage === 'overdue' ? 'withdrawal_restricted' : "deadline_{$targetStage}";
        $scope = array_filter(['zone_id' => $provider->zone_id, 'franchise_id' => $provider->franchise_id]);

        if ($provider->user) {
            $provider->user->notify(new KycNotification($event, ChannelResolver::resolve($scope)));
        }

        $provider->update(['kyc_reminder_stage' => $targetStage]);

        return true;
    }
}
