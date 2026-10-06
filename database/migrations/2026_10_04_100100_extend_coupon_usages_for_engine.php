<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Coupon engine C1 (design §2.2) — coupon_usages becomes the redemption record.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupon_usages', function (Blueprint $table) {
            $table->dropForeign(['booking_id']);
        });

        Schema::table('coupon_usages', function (Blueprint $table) {
            $table->unsignedBigInteger('booking_id')->nullable()->change();
        });

        Schema::table('coupon_usages', function (Blueprint $table) {
            $table->foreign('booking_id')->references('id')->on('bookings')->cascadeOnDelete();
            $table->foreignId('booking_bundle_id')->nullable()->constrained('booking_bundles')->nullOnDelete();
            $table->string('status', 12)->default('reserved');
            $table->decimal('original_amount', 10, 2)->nullable();
            $table->decimal('net_amount', 10, 2)->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->unique('booking_id');
        });
    }

    public function down(): void
    {
        Schema::table('coupon_usages', function (Blueprint $table) {
            $table->dropUnique(['booking_id']);
            $table->dropForeign(['booking_bundle_id']);
            $table->dropForeign(['booking_id']);
        });

        Schema::table('coupon_usages', function (Blueprint $table) {
            $table->dropColumn([
                'booking_bundle_id', 'status', 'original_amount', 'net_amount',
                'snapshot', 'reserved_at', 'confirmed_at', 'released_at',
            ]);
            $table->unsignedBigInteger('booking_id')->nullable(false)->change();
        });

        Schema::table('coupon_usages', function (Blueprint $table) {
            $table->foreign('booking_id')->references('id')->on('bookings')->cascadeOnDelete();
        });
    }
};
