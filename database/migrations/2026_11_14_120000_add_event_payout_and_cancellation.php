<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Events E3 (owner 2026-11-13): the organiser's earnings (one commission row per paid ticket order, credited after the
 * event + Event Settings' payout hold), and who cancelled an event, when and why (every buyer refunded in full).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->foreignId('event_order_id')->nullable()->after('hotel_reservation_id')->constrained()->nullOnDelete();
            $table->unique('event_order_id', 'commissions_event_order_id_unique');
        });
        Schema::table('events', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('approved_at');
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancellation_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason', 'completed_at']);
        });
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropUnique('commissions_event_order_id_unique');
            $table->dropConstrainedForeignId('event_order_id');
        });
    }
};
