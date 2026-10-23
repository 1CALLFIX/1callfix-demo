<?php

namespace App\Services\Backup;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * REF 1CF-BACKUP-RESTORE-001 — the separate restore database a chosen backup is loaded into for
 * comparing. Wiped and reloaded whenever a different backup is chosen; a marker table records which
 * backup it currently holds so a restore always uses exactly the backup that was compared.
 */
class RestoreSnapshot
{
    public const CONNECTION = 'restore';

    private const MARKER = '_restore_snapshot';

    public function __construct(private BackupShell $shell, private BackupCatalog $catalog) {}

    /**
     * Registers the restore connection (same server and user as live, database RESTORE_DB_DATABASE).
     * Refuses when it would point at the live database: loading a backup wipes this database first.
     */
    public static function connection(): string
    {
        if (config('database.connections.'.self::CONNECTION)) {
            return self::CONNECTION;
        }

        $database = config('backup.restore_database');
        $live = config('database.connections.'.config('database.default'));

        if (! $database) {
            throw new RuntimeException('The restore database is not set up on this server (RESTORE_DB_DATABASE, see docs/DISASTER_RECOVERY.md).');
        }
        if ($database === ($live['database'] ?? null)) {
            throw new RuntimeException('RESTORE_DB_DATABASE must not be the live database.');
        }

        config(['database.connections.'.self::CONNECTION => array_merge($live, ['database' => $database])]);

        return self::CONNECTION;
    }

    public function loadedBackupId(): ?string
    {
        $connection = self::connection();
        if (! Schema::connection($connection)->hasTable(self::MARKER)) {
            return null;
        }

        return DB::connection($connection)->table(self::MARKER)->value('backup_id');
    }

    /** Make sure the restore database holds exactly this backup. */
    public function ensureLoaded(array $backup): void
    {
        if ($this->loadedBackupId() === $backup['id']) {
            return;
        }

        $connection = self::connection();
        $database = config('database.connections.'.$connection.'.database');
        if ($database === config('database.connections.'.config('database.default').'.database')) {
            throw new RuntimeException('RESTORE_DB_DATABASE must not be the live database.');
        }

        $path = $this->catalog->fetch($backup);

        try {
            Schema::connection($connection)->dropAllTables();
            $this->shell->loadIntoRestoreDatabase($path, $database);
        } finally {
            // A downloaded copy is only needed for loading; the local nightly/hourly files stay.
            if (str_starts_with($path, config('backup.work_dir'))) {
                @unlink($path);
            }
        }

        Schema::connection($connection)->create(self::MARKER, function (Blueprint $t) {
            $t->string('backup_id');
            $t->timestamp('loaded_at');
        });
        DB::connection($connection)->table(self::MARKER)->insert(['backup_id' => $backup['id'], 'loaded_at' => now()]);
    }
}
