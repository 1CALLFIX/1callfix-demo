<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Generalizes `settings` from franchise-or-global to the full
// Global → Country → City → Zone → Module → Franchise cascade. Safe to run
// as one migration (not split like the franchises change): production's
// settings table has zero rows as of this writing — confirmed via the two
// prior verification runs, which both restore it to empty on exit — so
// there's no real data at risk in the backfill step below.
//
// Setting::get()/set() keep their existing two-argument call shape as the
// default (global scope); no caller needs to change.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('scope_type')->default('global')->after('id');
            $table->unsignedBigInteger('scope_id')->nullable()->after('scope_type');
        });

        // Backfill for any local/staging data that isn't empty like prod.
        DB::table('settings')->whereNotNull('franchise_id')->update(['scope_type' => 'franchise']);
        DB::statement('UPDATE settings SET scope_id = franchise_id WHERE franchise_id IS NOT NULL');

        // Drop the FK constraint FIRST — MySQL/InnoDB was reusing the
        // (franchise_id, key) unique index as the FK's required index (it
        // starts with the FK column, so no separate index got created for
        // it), so dropping that unique index before the FK fails with
        // "needed in a foreign key constraint". Dropping the FK first frees
        // it up; only then can the unique index and the column safely go.
        Schema::table('settings', function (Blueprint $table) {
            $table->dropForeign(['franchise_id']);
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique(['franchise_id', 'key']);
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('franchise_id');
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->unique(['scope_type', 'scope_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique(['scope_type', 'scope_id', 'key']);
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->foreignId('franchise_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        DB::statement("UPDATE settings SET franchise_id = scope_id WHERE scope_type = 'franchise'");

        Schema::table('settings', function (Blueprint $table) {
            $table->unique(['franchise_id', 'key']);
            $table->dropColumn(['scope_type', 'scope_id']);
        });
    }
};
