<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hotels H2b: what the customer pays is room price (price_quoted, the commission base) + GST + convenience fee =
 * amount_due, all from HotelPricing at booking time. payment_expires_at: an unpaid online booking holds its rooms only
 * until then (Hotel Settings), then hotels:expire-unpaid cancels it and frees the rooms (no payment, no booking).
 * guest_gst_number / guest_business_name: a business guest's GST invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_reservations', function (Blueprint $table) {
            $table->decimal('tax_amount', 10, 2)->default(0)->after('price_quoted');
            $table->decimal('convenience_fee', 10, 2)->default(0)->after('tax_amount');
            $table->decimal('amount_due', 10, 2)->nullable()->after('convenience_fee');
            $table->timestamp('payment_expires_at')->nullable()->after('payment_method');
            $table->string('guest_gst_number', 20)->nullable()->after('special_requests');
            $table->string('guest_business_name', 120)->nullable()->after('guest_gst_number');
            $table->index(['payment_status', 'payment_expires_at'], 'hotel_res_unpaid_idx');
        });
    }

    public function down(): void
    {
        Schema::table('hotel_reservations', function (Blueprint $table) {
            $table->dropIndex('hotel_res_unpaid_idx');
            $table->dropColumn(['tax_amount', 'convenience_fee', 'amount_due', 'payment_expires_at', 'guest_gst_number', 'guest_business_name']);
        });
    }
};
