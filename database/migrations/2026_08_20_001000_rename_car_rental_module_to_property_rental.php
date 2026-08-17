<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-08-17 slug rename (see App\Support\Modules::PROPERTY_RENTAL's own
 * docblock for the full rationale) -- `car_rental` was, from Phase 22.7
 * through Phase 25, actually Property Rental's own module code the entire
 * time; its display label read "Car Rental" regardless. No real Car Rental
 * (rentable-vehicle inventory) implementation ever existed anywhere in this
 * codebase, confirmed by a full-repository search before this migration.
 *
 * `modules.code` is the sole identity `module_activations.module_id`
 * references (a proper integer FK, not the code string) -- updating this
 * row's `code`/`name` in place preserves every existing `module_activations`
 * row's relationship untouched; nothing else needs migrating.
 *
 * The original seed migration (`2026_08_11_003000_create_modules_table`)
 * reads `App\Support\Modules::ALL` live at migration-run time, so any FRESH
 * database (including every automated test run's own `RefreshDatabase`
 * rebuild) already gets `property_rental` seeded directly by that migration
 * now that the class itself is renamed -- this migration exists only to fix
 * up a database that had already run the OLD seed migration before this
 * rename (i.e. an already-provisioned local/dev environment), where the row
 * would otherwise be stuck on the stale `car_rental` code.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('modules')
            ->where('code', 'car_rental')
            ->update(['code' => 'property_rental', 'name' => 'Property Rental', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('modules')
            ->where('code', 'property_rental')
            ->update(['code' => 'car_rental', 'name' => 'Car Rental', 'updated_at' => now()]);
    }
};
