<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The Services admin screen gets the same Reorder control as Categories and
// SubCategories, which needs somewhere to store the running order. Categories
// and subcategories already had sort_order from their create migrations;
// services never did, because ordering only became a thing once the app
// started rendering a service list per subcategory.
//
// Defaults to 0 for every existing row — the screen's reorder normalises a
// group to a clean 1..N run the first time an arrow is actually used, so a
// table full of zeroes is a valid starting state, not something to backfill.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
