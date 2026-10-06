<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Coupon engine C1 (design §2.4). price_quoted stays the GROSS price (decision D1);
// the discount lives in its own column and amountPayable() = gross - discount.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('coupon_discount_amount', 10, 2)->default(0);
            $table->json('coupon_snapshot')->nullable();
        });

        Schema::table('booking_bundles', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('coupon_discount_amount', 10, 2)->default(0);
            $table->json('coupon_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('booking_bundles', function (Blueprint $table) {
            $table->dropForeign(['coupon_id']);
            $table->dropColumn(['coupon_id', 'coupon_discount_amount', 'coupon_snapshot']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['coupon_discount_amount', 'coupon_snapshot']);
        });
    }
};
