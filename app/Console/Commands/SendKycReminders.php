<?php

namespace App\Console\Commands;

use App\Services\Kyc\KycReminderService;
use Illuminate\Console\Command;

/** Daily KYC deadline reminder/warning/overdue dispatch — see KycReminderService's own docblock for the idempotent milestone logic. */
class SendKycReminders extends Command
{
    protected $signature = 'kyc:send-reminders';

    protected $description = 'Sends KYC deadline reminder/warning/overdue notifications to providers approaching or past their kyc_deadline_at';

    public function handle(KycReminderService $service): int
    {
        $count = $service->dispatchDue();

        $this->info("Sent {$count} KYC reminder notification(s).");

        return self::SUCCESS;
    }
}
