<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// REF 1CF-ADMIN-ROWACTIONS-001 — reversible "Delete" (archive) for badges, performance
// campaigns, marketplace categories and add-ons. Additive only.
return new class extends Migration
{
    private const TABLES = ['badges', 'performance_campaigns', 'marketplace_categories', 'add_ons'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->softDeletes());
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropSoftDeletes());
        }
    }
};
