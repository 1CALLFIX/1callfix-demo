<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hotels step 2 (owner 2026-11-12, "stand against the aggregators"): coupons on hotel stays, online payment only.
 * price_quoted stays the room price before the coupon (the hotel's commission base: 1CallFix funds the coupon);
 * coupon_discount_amount is taken off the room price, GST is on what is left. One coupon usage per stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_reservations', function (Blueprint $table) {
            $table->string('coupon_code', 50)->nullable()->after('convenience_fee');
            $table->decimal('coupon_discount_amount', 10, 2)->default(0)->after('coupon_code');
        });
        Schema::table('coupon_usages', function (Blueprint $table) {
            $table->foreignId('hotel_reservation_id')->nullable()->after('marketplace_order_id')->constrained()->nullOnDelete();
            $table->unique('hotel_reservation_id');
        });
    }

    public function down(): void
    {
        Schema::table('coupon_usages', function (Blueprint $table) {
            $table->dropForeign(['hotel_reservation_id']);
            $table->dropUnique(['hotel_reservation_id']);
            $table->dropColumn('hotel_reservation_id');
        });
        Schema::table('hotel_reservations', function (Blueprint $table) {
            $table->dropColumn(['coupon_code', 'coupon_discount_amount']);
        });
    }
};
