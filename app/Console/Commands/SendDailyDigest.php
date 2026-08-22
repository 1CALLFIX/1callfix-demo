<?php

namespace App\Console\Commands;

use App\Services\Reporting\DailyDigestDispatchService;
use Illuminate\Console\Command;

/**
 * Daily Digest — see DailyDigestDispatchService's own docblock for the real
 * scoping/delivery logic. Thin command, same shape as SendKycReminders:
 * delegates entirely to the service so the logic is unit-testable without
 * an artisan process.
 */
class SendDailyDigest extends Command
{
    protected $signature = 'digest:send-daily';

    protected $description = 'Sends the scoped Daily Digest email (and best-effort WhatsApp summary) to every admin holding dashboard.view';

    public function handle(DailyDigestDispatchService $service): int
    {
        $result = $service->sendIfDue();

        if ($result['skipped']) {
            $this->info('Daily Digest: not due yet (or already sent today) — no-op.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Daily Digest: %d recipient(s), %d mailed (%d failed), %d WhatsApp sent (%d failed).',
            $result['recipients'], $result['mailed'], $result['mail_failed'], $result['whatsapp_sent'], $result['whatsapp_failed']
        ));

        return $result['mail_failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
