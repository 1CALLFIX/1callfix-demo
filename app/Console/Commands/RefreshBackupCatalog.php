<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupCatalog;
use Illuminate\Console\Command;

/**
 * REF 1CF-BACKUP-RESTORE-001 — keeps the off-site backup list cached so System > Backup & Restore opens at once
 * (listing the encrypted Drive takes seconds). Read-only; does nothing when off-site backup is not set up.
 */
class RefreshBackupCatalog extends Command
{
    protected $signature = 'backups:refresh-catalog';

    protected $description = 'Re-lists the off-site backups into the cache used by the Backup & Restore screen';

    public function handle(BackupCatalog $catalog): int
    {
        if (! config('backup.remote')) {
            $this->line('Off-site backup is not set up here; nothing to list.');

            return self::SUCCESS;
        }

        $catalog->refresh();
        $all = $catalog->all();

        if ($all['remote_error']) {
            $this->error($all['remote_error']);

            return self::FAILURE;
        }

        $this->info(count($all['backups']).' backups listed.');

        return self::SUCCESS;
    }
}
