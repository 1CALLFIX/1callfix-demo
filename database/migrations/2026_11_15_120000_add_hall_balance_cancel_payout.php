<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Function halls R3 (owner 2026-11-13): the balance and deposit paid online before the date, cancellation by the
 * customer (Hall Settings rule) or the owner (full refund), the owner paid after the event (one commission row per
 * booking), the deposit returned by itself unless the owner reports damage (the claim is decided by the team, R4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hall_bookings', function (Blueprint $table) {
            $table->decimal('advance_amount', 12, 2)->default(0)->after('pay_now'); // the part the cancellation rule keeps
            $table->foreignId('balance_payment_id')->nullable()->after('balance_due_on')->constrained('payments')->nullOnDelete();
            $table->timestamp('balance_paid_at')->nullable()->after('balance_payment_id');
            $table->date('balance_reminded_on')->nullable()->after('balance_paid_at');
            // none (no deposit / not paid yet) | held | returned | claimed (owner reported damage: the team decides)
            $table->string('deposit_status', 20)->default('none')->after('deposit_amount');
            $table->timestamp('deposit_returned_at')->nullable()->after('deposit_status');
            $table->string('damage_note', 1000)->nullable()->after('deposit_returned_at');
            $table->timestamp('damage_reported_at')->nullable()->after('damage_note');
            $table->decimal('kept_amount', 12, 2)->default(0)->after('refunded_amount'); // kept on a customer cancel: the owner's
            $table->timestamp('completed_at')->nullable()->after('cancelled_by');
        });
        // Bookings made before this step (none in production: Function Halls was never switched on).
        DB::table('hall_bookings')->where('paid_in_full', false)->update(['advance_amount' => DB::raw('pay_now')]);

        Schema::table('commissions', function (Blueprint $table) {
            $table->foreignId('hall_booking_id')->nullable()->after('event_order_id')->constrained()->nullOnDelete();
            $table->unique('hall_booking_id', 'commissions_hall_booking_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropUnique('commissions_hall_booking_id_unique');
            $table->dropConstrainedForeignId('hall_booking_id');
        });
        Schema::table('hall_bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('balance_payment_id');
            $table->dropColumn(['advance_amount', 'balance_paid_at', 'balance_reminded_on', 'deposit_status', 'deposit_returned_at',
                'damage_note', 'damage_reported_at', 'kept_amount', 'completed_at']);
        });
    }
};
