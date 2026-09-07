<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * STEP 2 of the Clear Data tool -- take a fresh mysqldump of exactly the
 * tables about to be cleared, then verify it non-trivially before
 * DataClearService is allowed to proceed. "mysqldump exited 0" is
 * deliberately NOT treated as sufficient proof on its own -- a truncated
 * or empty dump can still exit 0, and DEPLOYMENT_RUNBOOK.md already
 * documents exactly that failure mode for this team's existing manual
 * process ("A 0-byte or truncated dump is worse than no backup -- it
 * gives false confidence"). This class is what makes that check automatic
 * instead of an operator eyeballing a file size by hand.
 *
 * Uses --skip-extended-insert deliberately: mysqldump's default (batched,
 * multi-row INSERTs) makes an exact per-table row count from the dump
 * text alone unreliable without a real SQL parser. One INSERT statement
 * per row turns the row-count spot check into a simple, exact substring
 * count that can't be fooled by batching -- worth the larger (still
 * trivially small, given this system's real data volume per
 * ROLLBACK_PLAN.md) dump file.
 *
 * The dump is captured in memory and gzip-compressed with PHP's own zlib
 * (gzencode) rather than piping through a shell `gzip` binary -- portable
 * across the team's actual environments without assuming shell/gzip
 * availability, and avoids shell-escaping a command string entirely
 * (mysqldump runs via an argv array, never a shell).
 */
class DataClearBackupService
{
    /** Below this, a dump is certainly truncated/empty regardless of mysqldump's exit code. */
    private const MIN_DUMP_BYTES = 50;

    /**
     * @param  array<int,string>  $tables  real table names only -- the
     *                                     caller (DataClearService) must
     *                                     already have resolved these from
     *                                     DataClearCatalog and rejected
     *                                     anything on NEVER_CLEARABLE
     *                                     before this is ever called; this
     *                                     class does not re-check that.
     */
    public function backupAndVerify(array $tables): DataClearBackupResult
    {
        if (empty($tables)) {
            return DataClearBackupResult::failed('No tables given to back up.');
        }

        $driver = $this->currentDriver();
        if ($driver !== 'mysql') {
            return DataClearBackupResult::failed(
                "This tool requires a MySQL connection to take a mysqldump backup; the current connection driver is '{$driver}'."
            );
        }

        // Captured BEFORE the dump runs -- with --single-transaction,
        // mysqldump takes a consistent snapshot at that moment, so this is
        // the correct baseline to verify against. A row written AFTER this
        // point legitimately won't appear in the dump; that is not a
        // verification failure, just the normal meaning of a snapshot.
        $preCounts = collect($tables)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();

        $result = Process::timeout(300)->run($this->mysqldumpArgs($tables));

        if (! $result->successful()) {
            return DataClearBackupResult::failed(
                'mysqldump exited with an error: '.trim($result->errorOutput() ?: $result->output())
            );
        }

        $sql = $result->output();

        if (trim($sql) === '') {
            return DataClearBackupResult::failed('mysqldump produced empty output.');
        }

        $compressed = gzencode($sql, 9);
        if ($compressed === false) {
            return DataClearBackupResult::failed('Failed to gzip-compress the dump.');
        }

        $path = storage_path('app/data-clear-backups/'.now()->format('Y-m-d_His').'_'.Str::random(8).'.sql.gz');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $compressed);

        clearstatcache(true, $path);
        $sizeBytes = File::size($path);

        if ($sizeBytes < self::MIN_DUMP_BYTES) {
            return DataClearBackupResult::failed(
                "Backup file is only {$sizeBytes} bytes -- suspiciously small, treated as truncated/empty and refused."
            );
        }

        foreach ($tables as $table) {
            $dumpCount = substr_count($sql, 'INSERT INTO `'.$table.'`');
            if ($dumpCount !== $preCounts[$table]) {
                return DataClearBackupResult::failed(
                    "Row-count mismatch for `{$table}`: dump has {$dumpCount} row(s), live table had {$preCounts[$table]} at dump time. Refusing to trust this backup."
                );
            }
        }

        return DataClearBackupResult::ok($path, $sizeBytes, $preCounts);
    }

    /**
     * Its own method (not inlined) so a test can override it via a partial
     * mock -- this sandboxed test environment has no real MySQL server to
     * connect to at all (phpunit.xml runs every test against sqlite), so
     * the only honest way to exercise the REAL verification logic
     * (size floor, exact per-table row-count comparison against real fake
     * dump text) end-to-end is to stub just this one external fact
     * ("which driver is live") while every other line in this class runs
     * for real. The refusal path (driver genuinely isn't mysql) is tested
     * against the real sqlite test connection with no mock at all.
     */
    protected function currentDriver(): string
    {
        return DB::connection()->getDriverName();
    }

    /** @return array<int,string> argv for mysqldump -- run directly (no shell), so nothing here needs escaping. */
    private function mysqldumpArgs(array $tables): array
    {
        $config = config('database.connections.mysql');

        $args = array_filter([
            'mysqldump',
            '--single-transaction',
            '--quick',
            '--skip-extended-insert',
            '-h', $config['host'] ?? '127.0.0.1',
            '-P', (string) ($config['port'] ?? 3306),
            '-u', $config['username'] ?? '',
            (($config['password'] ?? '') !== '') ? '-p'.$config['password'] : null,
            $config['database'] ?? '',
            ...$tables,
        ], fn ($v) => $v !== null);

        return array_values($args);
    }
}
