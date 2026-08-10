<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Two additions, on two different axes — worth keeping straight because the
// old `placement` name was ambiguous between them:
//
// `placement` = WHICH SLOT the banner occupies on screen. Two sellable slots:
//   - `top` — the hero carousel at the top of the app / website home screen.
//     Premium inventory, charged at a higher rate.
//   - `mid` — the strip between modules, roughly half-way down the scroll.
//     Standard rate.
//   Deliberately a plain string, not a DB enum: adding a third slot later
//   (footer, category header, …) shouldn't mean rewriting the table. Allowed
//   values live on Banner::PLACEMENTS and are enforced by validation.
//   Defaults to `mid` on purpose — the cheaper, non-premium slot, so a row
//   created without an explicit choice never silently lands in paid-premium
//   inventory.
//
// `category_id` = WHAT the banner is targeted at, alongside the existing
//   franchise_id and zone_id. Null means "not category-specific". Lets a slot
//   be sold against a single category (e.g. an AC brand sponsoring
//   Appliance | AC Repair) rather than the whole app.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->string('placement')->default('mid')->after('zone_id')->index();
            $table->foreignId('category_id')->nullable()->after('placement')
                ->constrained('service_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropIndex(['placement']);
            $table->dropColumn(['placement', 'category_id']);
        });
    }
};
