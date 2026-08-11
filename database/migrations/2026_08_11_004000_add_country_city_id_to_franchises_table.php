<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Step 1 of 3 for the franchises.city/country → FK conversion. Nullable and
// alongside the existing string columns on purpose — the next migration
// backfills from those strings, and only the third drops them. Splitting
// this into three lets each step be verified independently rather than
// risking data loss in one big migration.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('franchises', function (Blueprint $table) {
            $table->foreignId('country_id')->nullable()->after('country')->constrained()->nullOnDelete();
            $table->foreignId('city_id')->nullable()->after('city')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('franchises', function (Blueprint $table) {
            $table->dropConstrainedForeignId('country_id');
            $table->dropConstrainedForeignId('city_id');
        });
    }
};
