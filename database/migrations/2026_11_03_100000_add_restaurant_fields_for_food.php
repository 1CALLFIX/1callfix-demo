<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Food delivery F1 (2026-11-03): a restaurant is a Store with module 'food' (the shared marketplace engine). These
 * are the restaurant-only facts the shop never needed. All additive and nullable: commerce shops are untouched.
 *
 *  - stores: FSSAI licence (required before a restaurant is approved, Food Settings), cuisines, cost for two,
 *    packaging charge per order, usual preparation time, and "busy" (extra minutes until a time).
 *  - store_hours: opening hours per weekday, more than one slot a day allowed (lunch + dinner). No rows = the
 *    open/closed switch alone decides (same as a shop today).
 *  - products: veg / egg / non-veg mark and "out of stock until" (the seller's "sold out for today").
 *  - add_on_groups + product_add_on_group: "Choose your base (pick 1)", "Extra toppings (up to 3)". An add-on may
 *    belong to one group; a product lists the groups it offers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('fssai_number', 20)->nullable()->after('pan');
            $table->string('cuisines', 255)->nullable()->after('fssai_number');
            $table->decimal('cost_for_two', 10, 2)->nullable()->after('cuisines');
            $table->decimal('packaging_charge', 10, 2)->default(0)->after('cost_for_two');
            $table->unsignedSmallInteger('prep_minutes')->nullable()->after('packaging_charge');
            $table->timestamp('busy_until')->nullable()->after('prep_minutes');
            $table->unsignedSmallInteger('busy_extra_minutes')->nullable()->after('busy_until');
        });

        Schema::create('store_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day'); // 0 = Sunday … 6 = Saturday (Carbon dayOfWeek)
            $table->time('opens_at');
            $table->time('closes_at'); // earlier than opens_at = closes after midnight
            $table->timestamps();
            $table->index(['store_id', 'day']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('food_type', 10)->nullable()->after('maximum_cart_quantity'); // veg | egg | non_veg
            $table->timestamp('out_of_stock_until')->nullable()->after('food_type');
        });

        Schema::create('add_on_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('min_select')->default(0);
            $table->unsignedTinyInteger('max_select')->default(1);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('add_ons', function (Blueprint $table) {
            $table->foreignId('add_on_group_id')->nullable()->after('store_id')->constrained('add_on_groups')->nullOnDelete();
        });

        Schema::create('product_add_on_group', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('add_on_group_id')->constrained('add_on_groups')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->unique(['product_id', 'add_on_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_add_on_group');
        Schema::table('add_ons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('add_on_group_id');
        });
        Schema::dropIfExists('add_on_groups');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['food_type', 'out_of_stock_until']);
        });
        Schema::dropIfExists('store_hours');
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['fssai_number', 'cuisines', 'cost_for_two', 'packaging_charge', 'prep_minutes', 'busy_until', 'busy_extra_minutes']);
        });
    }
};
