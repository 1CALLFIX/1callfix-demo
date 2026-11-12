<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hotels H3 — the stay. check_in_code: 4 digits on the customer's e-voucher, typed by the hotel at check-in (proves
 * the stay before the hotel is paid). accept_by: a paid booking at a hotel that must accept is cancelled and refunded
 * in full after this. no_show: the hotel marked "did not arrive" (status cancelled, Hotel Settings' nights charged).
 * cancelled_by: customer / hotel / admin / system, for the refund rule and the reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_reservations', function (Blueprint $table) {
            $table->string('check_in_code', 6)->nullable()->after('payment_expires_at');
            $table->timestamp('accept_by')->nullable()->after('check_in_code');
            $table->boolean('no_show')->default(false)->after('accept_by');
            $table->string('cancelled_by', 20)->nullable()->after('no_show');
            $table->index(['status', 'accept_by'], 'hotel_res_accept_idx');
        });
    }

    public function down(): void
    {
        Schema::table('hotel_reservations', function (Blueprint $table) {
            $table->dropIndex('hotel_res_accept_idx');
            $table->dropColumn(['check_in_code', 'accept_by', 'no_show', 'cancelled_by']);
        });
    }
};
