<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HOTEL / STAY BOOKING MODULE — start. Product decision (documented in
 * `HOTEL_MODULE_ARCHITECTURE.md`): Hotel/Stay is its OWN top-level module,
 * never nested inside `rental` (a fundamentally different inventory/
 * reservation shape — room-type quantity inventory + rate plans + guests,
 * not a single whole-unit date-range reservation like Property Rental).
 *
 * `App\Support\Modules::ALL` already carried a placeholder slot for this —
 * `'bookings' => 'Hotel Booking'` — seeded into the real `modules` table by
 * `2026_08_11_003000_create_modules_table` (which reads `Modules::ALL` at
 * migration-run time) since Phase 22.1, but with ZERO consumer anywhere
 * else in the codebase (confirmed by a full-repo grep before this
 * migration was written: the key appears only in `Modules::ALL` and
 * `ModuleCapabilities::MAP`, never as a string literal in any Action,
 * Controller, or test). Renaming this existing placeholder to `hotel`
 * follows the EXACT same safe, already-twice-proven precedent as the prior
 * `car_rental` -> `property_rental` -> `rental` renames (see those
 * migrations' own docblocks): `module_activations.module_id` is a proper
 * integer FK, never the code string, so updating this row's `code`/`name`
 * in place cannot orphan or break anything.
 *
 * `hotel` (not the bare `bookings`) is deliberately chosen over keeping the
 * placeholder slug as-is: `bookings` is already an extremely overloaded
 * identifier in this codebase (the real `bookings` table/`Booking` model
 * for the Service vertical, `App\Livewire\Bookings\*`, etc.) — reusing it
 * as a MODULE code too would be a real, confusing collision risk the
 * moment anyone reads `Modules::ALL['bookings']` next to the `bookings`
 * table and reasonably assumes they're related. They are not. `hotel` has
 * no such collision anywhere in the codebase (verified by grep).
 *
 * The seed migration (`2026_08_11_003000_create_modules_table`) reads
 * `App\Support\Modules::ALL` live at migration-run time, so any FRESH
 * database already gets `hotel` seeded directly once that class itself is
 * updated in this same commit — this migration exists only to fix up a
 * database that already ran the old seed (an already-provisioned local/dev
 * environment or a pre-existing production database), where the row would
 * otherwise be stuck on the stale `bookings` code.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('modules')
            ->where('code', 'bookings')
            ->update(['code' => 'hotel', 'name' => 'Hotel / Stay', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('modules')
            ->where('code', 'hotel')
            ->update(['code' => 'bookings', 'name' => 'Hotel Booking', 'updated_at' => now()]);
    }
};
