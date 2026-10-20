<?php

namespace Tests\Feature\Housekeeping;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Prod deploy 2026-10-20: a migration passed every test on SQLite and failed on MySQL because Laravel's
 * generated index name was 77 characters (MySQL limit: 64). Tests run on SQLite, which has no limit, so this
 * checks every table and index name the migrations create.
 */
class MysqlIdentifierLengthTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_table_and_index_name_fits_mysql_64_character_limit(): void
    {
        $tooLong = collect(DB::select("SELECT type, name FROM sqlite_master WHERE type IN ('table', 'index') AND name NOT LIKE 'sqlite_%'"))
            ->filter(fn ($row) => strlen($row->name) > 64)
            ->map(fn ($row) => "{$row->type} {$row->name} (".strlen($row->name).')')
            ->values()
            ->all();

        // Foreign keys are not named in SQLite; MySQL gets Laravel's default {table}_{column}_foreign unless the
        // migration passes constrained(indexName: ...). These already do (and are live on prod):
        $explicitlyNamed = [
            'performance_campaign_participants_performance_campaign_id_foreign', // perf_campaign_participant_fk
            'property_reservation_status_history_property_reservation_id_foreign', // prop_res_status_history_fk
            'accommodation_amenity_accommodation_accommodation_amenity_id_foreign', // accom_amenity_pivot_amenity_fk
        ];
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $table) {
            foreach (DB::select("PRAGMA foreign_key_list(\"{$table->name}\")") as $fk) {
                $name = "{$table->name}_{$fk->from}_foreign";
                if (strlen($name) > 64 && ! in_array($name, $explicitlyNamed, true)) {
                    $tooLong[] = "foreign key {$name} (".strlen($name).')';
                }
            }
        }

        $this->assertSame([], $tooLong, 'MySQL refuses identifiers longer than 64 characters — give these an explicit short name.');
    }
}
