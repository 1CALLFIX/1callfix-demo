<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One Rental (owner 2026-11-14: "why can't we add all rental into 1 module, 1 card, 1 settings"). Admin → Modules
 * keeps one "Rental" row; which kinds are offered (Function halls, Properties, Vehicles, Equipment) is Rental Settings.
 *
 * Every place where "Rental · Function halls" was on, Rental is now on (the Function halls kind is on by default;
 * Vehicles and Equipment are off by default, so switching Rental on there shows nothing new but halls and
 * properties). Then the halls module row and its activations go. Bookings, halls and partner roles are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $halls = DB::table('modules')->where('code', 'halls')->first();
        $rental = DB::table('modules')->where('code', 'rental')->first();
        if ($rental === null) {
            return;
        }
        $now = now();

        if ($halls !== null) {
            foreach (DB::table('module_activations')->where('module_id', $halls->id)->get() as $row) {
                $existing = DB::table('module_activations')->where('module_id', $rental->id)
                    ->where('scope_type', $row->scope_type)->where('scope_id', $row->scope_id)->first();
                if ($existing === null) {
                    DB::table('module_activations')->insert([
                        'module_id' => $rental->id, 'scope_type' => $row->scope_type, 'scope_id' => $row->scope_id,
                        'is_active' => $row->is_active, 'created_by_user_id' => $row->created_by_user_id, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                } elseif ($row->is_active && ! $existing->is_active) {
                    DB::table('module_activations')->where('id', $existing->id)->update(['is_active' => true, 'updated_at' => $now]);
                }
            }
            DB::table('modules')->where('id', $rental->id)->update([
                'is_implemented' => (bool) $rental->is_implemented || (bool) $halls->is_implemented,
                'sort_order' => min((int) $rental->sort_order, (int) $halls->sort_order),
            ]);
            DB::table('module_activations')->where('module_id', $halls->id)->delete();
            DB::table('modules')->where('id', $halls->id)->delete();
        }

        DB::table('modules')->where('id', $rental->id)->update(['name' => 'Rental', 'updated_at' => $now]);
    }

    public function down(): void
    {
        $rental = DB::table('modules')->where('code', 'rental')->first();
        if ($rental === null || DB::table('modules')->where('code', 'halls')->exists()) {
            return;
        }
        $now = now();
        $id = DB::table('modules')->insertGetId([
            'code' => 'halls', 'name' => 'Rental · Function halls', 'sort_order' => (int) $rental->sort_order, 'is_active' => true,
            'is_implemented' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach (DB::table('module_activations')->where('module_id', $rental->id)->get() as $row) {
            DB::table('module_activations')->insert([
                'module_id' => $id, 'scope_type' => $row->scope_type, 'scope_id' => $row->scope_id, 'is_active' => $row->is_active,
                'created_by_user_id' => $row->created_by_user_id, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('modules')->where('id', $rental->id)->update(['name' => 'Rental · Vehicles & equipment', 'sort_order' => (int) $rental->sort_order + 1, 'updated_at' => $now]);
    }
};
