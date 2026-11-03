<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Food (2026-11-03): F1–F5 are built (restaurants, order flow, customer pages, problems & refunds, dashboard,
 * sign-up), so the Modules screen may now switch Food on — the same step commerce got on 2026-10-29. It stays
 * INACTIVE everywhere until an admin activates it per country / city / zone / franchise. Grocery and pharmacy stay
 * unimplemented.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('modules')->where('code', 'food')->update(['is_implemented' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('modules')->where('code', 'food')->update(['is_implemented' => false, 'updated_at' => now()]);
    }
};
