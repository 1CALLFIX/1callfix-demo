<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hotels step 3 (owner 2026-11-12, "stand against the aggregators"):
 * - hotel_room_types.non_refundable_percent: a room may also be sold this % cheaper with no refund (null = not offered);
 * - hotel_reservations.non_refundable: the guest chose that price (cancelling or not arriving keeps the whole amount);
 * - hotel_reservations.replaces_reservation_id: "Change dates" — the new booking that replaces an older one. When the new
 *   one is confirmed, the old one is cancelled free and refunded in full.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_room_types', function (Blueprint $table) {
            $table->decimal('non_refundable_percent', 5, 2)->nullable()->after('total_inventory');
        });
        Schema::table('hotel_reservations', function (Blueprint $table) {
            $table->boolean('non_refundable')->default(false)->after('coupon_discount_amount');
            $table->foreignId('replaces_reservation_id')->nullable()->after('non_refundable')->constrained('hotel_reservations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hotel_reservations', function (Blueprint $table) {
            $table->dropForeign(['replaces_reservation_id']);
            $table->dropColumn(['non_refundable', 'replaces_reservation_id']);
        });
        Schema::table('hotel_room_types', function (Blueprint $table) {
            $table->dropColumn('non_refundable_percent');
        });
    }
};
