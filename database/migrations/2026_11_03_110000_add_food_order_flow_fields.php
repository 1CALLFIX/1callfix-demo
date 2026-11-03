<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Food F2 — the restaurant order flow. All columns are additive and nullable / zero by default, so shop orders are
 * unchanged.
 *  - packaging_charge: the restaurant's own charge, capped by Food Settings (0 for shops).
 *  - accept_by: the restaurant must accept before this (food.accept_minutes after the order is placed or paid).
 *  - ready_by: the time the restaurant promised the food, set when it accepts.
 *  - rider_called_at: when a rider was called while the food was still cooking (food.rider_call_before_ready_minutes).
 *  - distance_km: restaurant to delivery address, straight line, when the distance fee or radius is in use.
 *  - tax_by_platform: GST charged under section 9(5): the platform collects and pays it, not the restaurant.
 *  - marketplace_settlements.platform_gst_amount: that GST, kept out of the restaurant's share.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->decimal('packaging_charge', 10, 2)->default(0)->after('delivery_fee');
            $table->decimal('distance_km', 6, 2)->nullable()->after('packaging_charge');
            $table->boolean('tax_by_platform')->default(false)->after('tax_amount');
            $table->timestamp('accept_by')->nullable()->after('confirmed_at');
            $table->timestamp('ready_by')->nullable()->after('accept_by');
            $table->timestamp('rider_called_at')->nullable()->after('ready_by');
            $table->index(['module', 'status', 'accept_by'], 'mo_food_accept_idx');
        });

        Schema::table('marketplace_settlements', function (Blueprint $table) {
            $table->decimal('platform_gst_amount', 10, 2)->default(0)->after('goods_franchise');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_settlements', function (Blueprint $table) {
            $table->dropColumn('platform_gst_amount');
        });

        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->dropIndex('mo_food_accept_idx');
            $table->dropColumn(['packaging_charge', 'distance_km', 'tax_by_platform', 'accept_by', 'ready_by', 'rider_called_at']);
        });
    }
};
