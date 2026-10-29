<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** E-commerce: coupons on shop orders — a usage row can belong to a marketplace order (one per order). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupon_usages', function (Blueprint $table) {
            $table->foreignId('marketplace_order_id')->nullable()->after('booking_bundle_id')->constrained()->nullOnDelete();
            $table->unique('marketplace_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('coupon_usages', function (Blueprint $table) {
            $table->dropUnique(['marketplace_order_id']);
            $table->dropConstrainedForeignId('marketplace_order_id');
        });
    }
};
