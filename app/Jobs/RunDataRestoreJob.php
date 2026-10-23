<?php

namespace App\Jobs;

use App\Models\DataRestore;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Backup\BackupCatalog;
use App\Services\Backup\BackupShell;
use App\Services\Backup\RestoreSnapshot;
use App\Services\Backup\ScopedRestoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * REF 1CF-BACKUP-RESTORE-001 — the slow half of Backup & Restore > Restore one, run on the queue:
 *  - compare:         load the chosen backup into the restore database, compare the item with live
 *  - restore_missing: safety copy of the tables it writes, then bring back missing/archived records
 *  - restore_row:     safety copy of that table, then put one changed record back to its backup values
 * One at a time (the restore database holds one backup). Never retried automatically.
 */
class RunDataRestoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(
        public int $restoreId,
        public string $mode,
        public ?int $userId = null,
        public ?string $table = null,
        public ?int $rowId = null,
    ) {}

    public function handle(BackupCatalog $catalog, BackupShell $shell, RestoreSnapshot $snapshot): void
    {
        $restore = DataRestore::findOrFail($this->restoreId);
        $lock = Cache::lock('backup-restore', $this->timeout);

        if (! $lock->get()) {
            $this->markFailed($restore, 'Another restore is running. Try again in a minute.');

            return;
        }

        try {
            $snapshot->ensureLoaded($catalog->find($restore->backup_id));
            $service = new ScopedRestoreService(RestoreSnapshot::connection());

            match ($this->mode) {
                'compare' => $this->compare($restore, $service),
                'restore_missing' => $this->restoreMissing($restore, $service, $shell),
                'restore_row' => $this->restoreRow($restore, $service, $shell),
            };
        } catch (Throwable $e) {
            Log::warning('Backup & Restore '.$this->mode.' failed', ['restore' => $restore->id, 'error' => $e->getMessage()]);
            $this->markFailed($restore, $e->getMessage());
        } finally {
            $lock->release();
        }
    }

    private function compare(DataRestore $restore, ScopedRestoreService $service): void
    {
        $restore->update([
            'scope_label' => $service->labelFor($restore->scope_type, $restore->scope_id) ?? $restore->scope_label,
            'summary' => $service->compare($restore->scope_type, $restore->scope_id),
            'status' => DataRestore::READY,
            'error' => null,
        ]);
    }

    private function restoreMissing(DataRestore $restore, ScopedRestoreService $service, BackupShell $shell): void
    {
        $tables = $service->tablesToWrite($restore->scope_type, $restore->scope_id);
        if ($tables === []) {
            $restore->update(['status' => DataRestore::DONE, 'result' => ['inserted' => [], 'undeleted' => []], 'finished_at' => now()]);

            return;
        }

        $safety = $this->safetyCopy($restore, $shell, $tables);
        $result = $service->restoreMissing($restore->scope_type, $restore->scope_id);

        $restore->update([
            'status' => DataRestore::DONE,
            'result' => $result,
            'safety_copy_path' => $safety,
            'summary' => $service->compare($restore->scope_type, $restore->scope_id),
            'finished_at' => now(),
            'error' => null,
        ]);

        ActivityLogger::logModel(User::find($this->userId), $restore, 'restored from backup', [
            'item' => $restore->scope_type.' #'.$restore->scope_id.' '.$restore->scope_label,
            'backup' => $restore->backup_id,
            'reason' => $restore->reason,
            'inserted' => $result['inserted'],
            'undeleted' => $result['undeleted'],
            'safety_copy' => basename($safety),
        ]);
    }

    private function restoreRow(DataRestore $restore, ScopedRestoreService $service, BackupShell $shell): void
    {
        if (! $this->table || ! $this->rowId) {
            throw new RuntimeException('No record chosen.');
        }

        $safety = $this->safetyCopy($restore, $shell, [$this->table]);
        $values = $service->restoreRow($restore->scope_type, $restore->scope_id, $this->table, $this->rowId);

        $result = $restore->result ?? [];
        $result['rows'][] = ['table' => $this->table, 'id' => $this->rowId, 'at' => now()->toDateTimeString(), 'safety_copy' => basename($safety)];

        $restore->update([
            'status' => DataRestore::READY,
            'result' => $result,
            'summary' => $service->compare($restore->scope_type, $restore->scope_id),
            'error' => null,
        ]);

        ActivityLogger::logModel(User::find($this->userId), $restore, 'restored one record from backup', [
            'table' => $this->table,
            'id' => $this->rowId,
            'backup' => $restore->backup_id,
            'reason' => $restore->reason,
            'columns' => array_keys($values),
            'safety_copy' => basename($safety),
        ]);
    }

    private function safetyCopy(DataRestore $restore, BackupShell $shell, array $tables): string
    {
        $path = config('backup.work_dir').'/before-restore-'.$restore->id.'-'.now()->format('Y-m-d_H-i-s').'.sql.gz';
        $shell->dumpTables($tables, $path);

        return $path;
    }

    private function markFailed(DataRestore $restore, string $message): void
    {
        // A failed single-record restore leaves the comparison usable; anything else ends the attempt.
        $restore->update([
            'status' => $this->mode === 'restore_row' && $restore->summary ? DataRestore::READY : DataRestore::FAILED,
            'error' => $message,
            'finished_at' => $this->mode === 'restore_row' ? $restore->finished_at : now(),
        ]);
    }
}
