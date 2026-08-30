<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Adds soft deletes to `banners`. The admin Banner Manager's delete action
// was a hard `DELETE` (Banners\Manage::deleteBanner) — fine while nothing
// referenced a banner, but a sold ad slot (price_paid set) is a revenue
// record, and an accidental delete of a live campaign had no undo. A
// `deleted_at` column keeps the row for audit / possible restore while
// SoftDeletes' global scope keeps it out of every existing query
// (forSlot(), currentlyLive(), the admin list, reorder, revenue sums)
// with no other change.
//
// The single column addition asked for — no other schema change.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
