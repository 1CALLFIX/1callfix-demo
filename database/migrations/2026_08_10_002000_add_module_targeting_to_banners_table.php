<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fourth and final targeting axis: `module` — sell a slot against a whole
// vertical ("everything in Food Delivery") rather than one category inside it.
// Null means not module-specific, same wildcard convention as franchise_id,
// zone_id and category_id.
//
// Why a plain nullable column and not a polymorphic banner_targets pivot:
// a pivot buys multi-target selling (one banner across three zones), which
// needs a join on every home-screen render — the hottest read path in the
// app — and isn't worth it at one franchise and two zones. The four axes
// here are bounded by the domain (who / where / which vertical / which
// category); they aren't an open-ended list. If multi-target selling ever
// becomes a real product, the migration path is additive: create the pivot,
// backfill one row per non-null column, and change Banner::forSlot() — which
// by design is the only place that reads these columns.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->string('module')->nullable()->after('zone_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropIndex(['module']);
            $table->dropColumn('module');
        });
    }
};
