<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parcel "Pay at: Pickup | Drop" (owner decision 2026-10-24). A cash parcel is paid to the rider either by the
 * sender at pickup (as before) or by the recipient at drop. Nothing waits for the recipient: the order goes to
 * riders at once, and a recipient who refuses at the door is handled by the rider (bring it back to the sender).
 *
 * `cash_collect_at` is a plain string (pickup|drop), not an enum, so no enum widening is involved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcel_orders', function (Blueprint $table) {
            $table->string('cash_collect_at', 10)->default('pickup')->after('payment_method');
            $table->timestamp('recipient_refused_at')->nullable()->after('recipient_phone');
        });
    }

    public function down(): void
    {
        Schema::table('parcel_orders', function (Blueprint $table) {
            $table->dropColumn(['cash_collect_at', 'recipient_refused_at']);
        });
    }
};
