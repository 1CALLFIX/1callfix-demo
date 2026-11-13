<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner 2026-11-13 ("rental module is not ready to activate"): Rental is two rows on Admin → Modules, side by side and
 * named so nobody guesses: "Rental · Function halls" (built, can be switched on) and "Rental · Vehicles & equipment"
 * (not built yet). Names and order only; codes, activations and data are untouched.
 */
return new class extends Migration
{
    private const ORDER = ['service', 'parcel', 'halls', 'rental', 'food', 'grocery', 'pharmacy', 'commerce', 'taxi', 'hotel', 'events'];

    public function up(): void
    {
        DB::table('modules')->where('code', 'halls')->update(['name' => 'Rental · Function halls', 'updated_at' => now()]);
        DB::table('modules')->where('code', 'rental')->update(['name' => 'Rental · Vehicles & equipment', 'updated_at' => now()]);
        foreach (self::ORDER as $i => $code) {
            DB::table('modules')->where('code', $code)->update(['sort_order' => $i + 1]);
        }
    }

    public function down(): void
    {
        DB::table('modules')->where('code', 'halls')->update(['name' => 'Function Halls']);
        DB::table('modules')->where('code', 'rental')->update(['name' => 'Rental']);
        foreach (['service', 'parcel', 'rental', 'food', 'grocery', 'pharmacy', 'commerce', 'taxi', 'hotel', 'events', 'halls'] as $i => $code) {
            DB::table('modules')->where('code', $code)->update(['sort_order' => $i + 1]);
        }
    }
};
