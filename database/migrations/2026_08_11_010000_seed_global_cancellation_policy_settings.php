<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Seeds the confirmed global default cancellation policy (15 min free
// window, flat ₹0 fee — matching cancellation_policies' own column
// defaults, kept only as a naming/value reference) as real Setting rows,
// not a cancellation_policies row.
//
// Deliberate architecture choice: cancellation_policies is a dedicated
// franchise_id-only table — using it here would mean building a SECOND
// scope-resolution mechanism alongside Setting's existing Global→Country→
// City→Zone→Module→Franchise cascade, which is exactly what this phase of
// work was told not to do ("do not create another settings architecture",
// "do not redo the scope resolver"). Routing cancellation.* through the
// existing Setting::get()/set() gives it the full six-level cascade for
// free, with zero new resolver code. cancellation_policies stays in the
// schema, unused, same status as before this migration.
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('settings')->insert([
            ['scope_type' => 'global', 'scope_id' => null, 'key' => 'cancellation.free_minutes', 'value' => '15', 'created_at' => $now, 'updated_at' => $now],
            ['scope_type' => 'global', 'scope_id' => null, 'key' => 'cancellation.fee_type', 'value' => 'flat', 'created_at' => $now, 'updated_at' => $now],
            ['scope_type' => 'global', 'scope_id' => null, 'key' => 'cancellation.fee_value', 'value' => '0', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('scope_type', 'global')->whereIn('key', [
            'cancellation.free_minutes', 'cancellation.fee_type', 'cancellation.fee_value',
        ])->delete();
    }
};
