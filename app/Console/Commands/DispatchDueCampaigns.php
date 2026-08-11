<?php

namespace App\Console\Commands;

use App\Services\CampaignService;
use Illuminate\Console\Command;

/**
 * Sends every scheduled Campaign (promotions, meeting reminders, app
 * announcements, etc.) whose scheduled_at has arrived. Registered against
 * Laravel's own scheduler in routes/console.php — the first use of it in
 * this app, not a second scheduling engine.
 */
class DispatchDueCampaigns extends Command
{
    protected $signature = 'campaigns:dispatch-due';

    protected $description = 'Send all notification campaigns whose scheduled_at has arrived';

    public function handle(CampaignService $campaignService): int
    {
        $sent = $campaignService->sendAllDue();

        $this->info("Dispatched {$sent} due campaign(s).");

        return self::SUCCESS;
    }
}
