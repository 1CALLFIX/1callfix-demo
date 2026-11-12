<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hotels (2026-11-12): H1–H4 are built (hotel sign-up + portal, customer pages, booking + payment, the stay, reviews),
 * so the Modules screen may now switch Hotel / Stay on — the same step Food got on 2026-11-03. It stays INACTIVE
 * everywhere until an admin activates it per country / city / zone / franchise.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('modules')->where('code', 'hotel')->update(['is_implemented' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('modules')->where('code', 'hotel')->update(['is_implemented' => false, 'updated_at' => now()]);
    }
};
