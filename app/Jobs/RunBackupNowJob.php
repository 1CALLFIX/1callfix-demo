<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Backup\BackupCatalog;
use App\Services\Backup\BackupShell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** REF 1CF-BACKUP-RESTORE-001 — Backup & Restore > "Back up now": one run of scripts/backup-offsite.sh hourly. */
class RunBackupNowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const STATUS_KEY = 'backup-now.status';

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public ?int $userId = null) {}

    public function handle(BackupShell $shell, BackupCatalog $catalog): void
    {
        try {
            $output = $shell->runBackupScript();
            Cache::put(self::STATUS_KEY, ['state' => 'done', 'message' => $output ?: 'Backup made.', 'at' => now()->toDateTimeString()], now()->addDay());
            ActivityLogger::log(User::find($this->userId), 'backup', 0, 'backup made now', ['output' => $output]);
        } catch (Throwable $e) {
            Cache::put(self::STATUS_KEY, ['state' => 'failed', 'message' => $e->getMessage(), 'at' => now()->toDateTimeString()], now()->addDay());
        } finally {
            $catalog->forgetCache();
        }
    }
}
