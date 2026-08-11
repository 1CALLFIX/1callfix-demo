<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Reuses the existing `payments` table + PaymentController webhook path for
// wallet top-ups, rather than a second payment-record system — a top-up
// isn't a booking, so booking_id has to become nullable (kept cascadeOnDelete
// for the booking-payment case; a null FK is simply unaffected by any
// booking's deletion), and something has to identify WHO to credit when
// there's no booking to derive a customer from.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['booking_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('booking_id')->nullable()->change();
            $table->enum('purpose', ['booking', 'wallet_topup'])->default('booking')->after('booking_id');
            $table->foreignId('user_id')->nullable()->after('purpose')->constrained()->nullOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('booking_id')->references('id')->on('bookings')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['purpose', 'user_id']);
        });
    }
};
