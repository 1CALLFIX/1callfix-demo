<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// REF 1CF-BACKUP-RESTORE-001 (Layer 1): deleting a country, city, zone, category or subcategory from the
// admin now archives it (deleted_at) so it can be restored from Backup & Restore -> Deleted items.
// Additive only; down() drops exactly the columns up() added.
return new class extends Migration
{
    private const TABLES = ['countries', 'cities', 'zones', 'service_categories', 'service_subcategories'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, fn (Blueprint $t) => $t->softDeletes());
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropSoftDeletes());
            }
        }
    }
};
