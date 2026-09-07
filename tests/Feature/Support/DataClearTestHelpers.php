<?php

namespace Tests\Feature\Support;

use App\Services\Operations\DataClearBackupService;
use App\Services\Operations\DataClearCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Shared setup for the Clear Data tool's tests. This sandboxed test suite
 * has no real MySQL server to connect to (phpunit.xml runs everything
 * against sqlite) — bindWorkingDataClearBackup() is the one seam that
 * stubs "which DB driver is live" (DataClearBackupService::currentDriver(),
 * see its own docblock for why) while every other line of REAL
 * verification logic (size floor, exact per-table row-count comparison)
 * still runs against Process::fake()'s canned mysqldump output, built to
 * exactly match whatever rows are actually live in the test database at
 * call time — so a genuinely correct backup is produced, not a rubber
 * stamp.
 */
trait DataClearTestHelpers
{
    protected function bindWorkingDataClearBackup(): void
    {
        $fake = \Mockery::mock(DataClearBackupService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $fake->shouldReceive('currentDriver')->andReturn('mysql');
        $this->app->instance(DataClearBackupService::class, $fake);

        Process::fake(function ($process) {
            $known = DataClearCatalog::tablesFor(DataClearCatalog::allModuleKeys());
            $tables = array_values(array_intersect((array) $process->command, $known));

            // A real mysqldump is never a literally empty (or tiny) string
            // even for an all-zero-row selection -- header comments,
            // CREATE TABLE statements, SET/LOCK/UNLOCK TABLES, etc. are
            // always present and add up to a real amount of text. This
            // fake's header is padded to roughly match that reality so an
            // unseeded table doesn't trip DataClearBackupService's (correct)
            // empty-output or suspiciously-small-file checks for a reason
            // that has nothing to do with what's actually under test.
            $sql = "-- fake mysqldump\n-- Host: fake  Database: fake\n-- ------------------------------------------------------\n"
                ."SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET TIME_ZONE='+00:00';\n";
            foreach ($tables as $table) {
                $count = DB::table($table)->count();
                for ($i = 0; $i < $count; $i++) {
                    $sql .= "INSERT INTO `{$table}` VALUES ({$i});\n";
                }
            }

            return Process::result(output: $sql, exitCode: 0);
        });
    }
}
