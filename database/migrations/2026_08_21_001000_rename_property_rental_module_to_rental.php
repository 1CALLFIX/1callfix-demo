<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RENTAL MODULE IMPLEMENTATION — start. Same exact pattern as the prior
 * `car_rental` -> `property_rental` rename (see that migration's own
 * docblock). This time the rename reflects a real, explicit product
 * decision (not a naming-collision fix): 1CallFix has ONE top-level Rental
 * module (`rental`), not three (`property_rental`/`vehicle_rental`/
 * `equipment_rental`). Property, Vehicle and Equipment are `rental_type`
 * values UNDER that one module, not separate module activation records.
 *
 * `modules.code` is the sole identity `module_activations.module_id`
 * references (a proper integer FK, not the code string) -- updating this
 * row's `code`/`name` in place preserves every existing `module_activations`
 * row's relationship untouched; nothing else needs migrating. Any franchise
 * that already had Property Rental activated keeps that activation, now
 * covering the whole Rental vertical (Property today, Vehicle/Equipment as
 * soon as their own inventory exists) -- consistent with "Rental disabled
 * -> Rental operations blocked; Rental enabled -> valid Rental operations
 * allowed" for all three rental types at once.
 *
 * The seed migration (`2026_08_11_003000_create_modules_table`) reads
 * `App\Support\Modules::ALL` live at migration-run time, so any FRESH
 * database already gets `rental` seeded directly by that migration now
 * that the class itself is updated -- this migration exists only to fix up
 * a database that already ran the old seed (i.e. an already-provisioned
 * local/dev environment or a pre-existing production database), where the
 * row would otherwise be stuck on the stale `property_rental` code.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('modules')
            ->where('code', 'property_rental')
            ->update(['code' => 'rental', 'name' => 'Rental', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('modules')
            ->where('code', 'rental')
            ->update(['code' => 'property_rental', 'name' => 'Property Rental', 'updated_at' => now()]);
    }
};
