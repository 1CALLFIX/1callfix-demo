<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decision 2026-10-17 — the part of a booking paid from the customer's referral balance.
 * Like coupon_discount_amount: price_quoted stays gross (commission, payout and fee bases are
 * untouched) and Booking::amountPayable() subtracts it, so Razorpay collects only the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('referral_balance_used', 10, 2)->default(0)->after('coupon_discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('referral_balance_used');
        });
    }
};
