<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Step 3 of 3 — destructive, run only after the backfill (previous
// migration) has been verified against production data. Held back from the
// first deploy of this batch on purpose: this drops columns, the backfill
// doesn't, and those deserve different levels of caution even in the same
// piece of work.
return new class extends Migration
{
    public function up(): void
    {
        // The FKs from migration 004 are ON DELETE SET NULL — appropriate
        // while the columns were nullable, but MySQL refuses to make a
        // column NOT NULL while its FK's delete rule could try to null it
        // (error 1830). A franchise should never lose its Country/City out
        // from under it anyway — restrictOnDelete is the right rule now,
        // not just a workaround: it blocks deleting a Country/City that
        // still has franchises, same "refuse and point at deactivation
        // instead" posture Franchises\Manage already uses for zones/
        // providers/bookings.
        Schema::table('franchises', function (Blueprint $table) {
            $table->dropForeign(['country_id']);
            $table->dropForeign(['city_id']);
        });

        Schema::table('franchises', function (Blueprint $table) {
            $table->foreignId('country_id')->nullable(false)->change();
            $table->foreignId('city_id')->nullable(false)->change();
        });

        Schema::table('franchises', function (Blueprint $table) {
            $table->foreign('country_id')->references('id')->on('countries')->restrictOnDelete();
            $table->foreign('city_id')->references('id')->on('cities')->restrictOnDelete();
        });

        Schema::table('franchises', function (Blueprint $table) {
            $table->dropColumn(['country', 'city']);
        });
    }

    public function down(): void
    {
        Schema::table('franchises', function (Blueprint $table) {
            $table->string('country')->default('India')->after('country_id');
            $table->string('city')->after('city_id');
        });

        Schema::table('franchises', function (Blueprint $table) {
            $table->dropForeign(['country_id']);
            $table->dropForeign(['city_id']);
        });

        Schema::table('franchises', function (Blueprint $table) {
            $table->foreignId('country_id')->nullable()->change();
            $table->foreignId('city_id')->nullable()->change();
        });

        Schema::table('franchises', function (Blueprint $table) {
            $table->foreign('country_id')->references('id')->on('countries')->nullOnDelete();
            $table->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
        });

        // Does not restore the string values themselves — down() here is a
        // structural rollback (get the columns back), not a data
        // rollback. Repopulating city/country strings from the FK rows
        // would need a small script, not this migration.
    }
};
