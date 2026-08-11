<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Manual priority ranking criterion (Priority/Ranking phase) -- no such
// column existed on providers before this (confirmed by audit: services/
// categories/subcategories/banners all already had sort_order + a reorder
// UI, providers had nothing). Default 0 so every existing provider ranks
// identically on this criterion until an admin sets one -- no behaviour
// change unless 'priority' is actually added to the ranking config.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->integer('priority')->default(0)->after('rating_avg');
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn('priority');
        });
    }
};
