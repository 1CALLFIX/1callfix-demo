<?php

namespace Tests\Feature\Backup;

use Illuminate\Support\Facades\DB;

/**
 * REF 1CF-BACKUP-RESTORE-001 — a second sqlite database standing in for the restore database:
 * takeSnapshot() copies the live test database into it, as loading a real backup would.
 */
trait SnapshotTestHelpers
{
    protected function registerSnapshotConnection(): void
    {
        config(['database.connections.restore' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('restore');
    }

    protected function takeSnapshot(): void
    {
        $snap = DB::connection('restore');
        foreach ($snap->select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'") as $t) {
            $snap->statement('drop table "'.$t->name.'"');
        }

        foreach (DB::select("select name, sql from sqlite_master where type = 'table' and name not like 'sqlite_%'") as $t) {
            $snap->statement($t->sql);
            foreach (array_chunk(array_map(fn ($r) => (array) $r, DB::table($t->name)->get()->all()), 100) as $rows) {
                $snap->table($t->name)->insert($rows);
            }
        }
    }
}
