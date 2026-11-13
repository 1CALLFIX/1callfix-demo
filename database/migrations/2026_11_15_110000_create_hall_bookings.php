<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Function halls R2 (owner 2026-11-13): customers book a hall online.
 * - hall_bookings: one date + slot of one hall. No payment, no booking: an online booking holds the slot for Hall
 *   Settings' unpaid hold. Paid → the owner accepts within Hall Settings' hours, or it is declined and refunded in full.
 *   Prices are copied in as charged (the owner may change prices later).
 * - payments.hall_booking_id and purpose 'hall_booking' (the Razorpay capture webhook marks the booking paid).
 * - modules.halls is_implemented = true: an admin may now switch Function Halls on per city (Admin → Modules).
 *
 * payments.purpose before this migration — the 12 values read from production with SHOW COLUMNS on 2026-11-13 plus
 * 'event_order' (Events E2, deployed a6ecf6b): 13 values. Re-read production before deploying (runbook step 5).
 */
return new class extends Migration
{
    private const CURRENT = [
        'booking', 'wallet_topup', 'plan_subscription', 'parcel_order', 'taxi_ride', 'property_reservation',
        'marketplace_order', 'rental_reservation', 'hotel_reservation', 'booking_bundle', 'cancellation_fee', 'parcel_pack',
        'event_order',
    ];

    public function up(): void
    {
        Schema::create('hall_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('hall_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hall_slot_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->date('event_date');
            $table->string('slot_name', 60);
            $table->dateTime('starts_at'); // UTC; a slot past midnight ends the next day
            $table->dateTime('ends_at');
            $table->string('occasion', 80)->nullable();
            $table->unsignedInteger('guests')->nullable();
            $table->string('customer_name', 120);
            $table->string('customer_phone', 20)->nullable();
            $table->string('notes', 1000)->nullable();
            // pending_payment → awaiting_owner → confirmed → completed; declined (owner said no / no answer) | expired
            // (not paid in time) | cancelled
            $table->string('status', 20)->default('pending_payment');
            $table->string('payment_method', 20);
            $table->string('payment_status', 20)->default('pending'); // pending | paid | refunded | partially_refunded
            $table->decimal('hall_price', 12, 2);
            $table->decimal('gst_percent', 5, 2)->default(0);
            $table->decimal('gst_amount', 12, 2)->default(0);
            $table->boolean('platform_collects_gst')->default(true);
            $table->decimal('booking_fee', 10, 2)->default(0);
            $table->decimal('total', 12, 2); // hall price + GST + booking fee
            $table->decimal('deposit_amount', 12, 2)->default(0); // refundable, paid with the balance
            $table->decimal('pay_now', 12, 2); // the advance (or everything when the date is close)
            $table->boolean('paid_in_full')->default(false);
            $table->date('balance_due_on')->nullable();
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('refunded_amount', 12, 2)->default(0);
            $table->timestamp('payment_expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('accept_by')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->string('decline_reason', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['hall_id', 'status', 'starts_at']);
            $table->index(['status', 'accept_by']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('hall_booking_id')->nullable()->after('event_order_id')->constrained()->nullOnDelete();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('purpose', [...self::CURRENT, 'hall_booking'])->default('booking')->change();
        });

        DB::table('modules')->where('code', 'halls')->update(['is_implemented' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('modules')->where('code', 'halls')->update(['is_implemented' => false, 'updated_at' => now()]);
        DB::table('payments')->where('purpose', 'hall_booking')->update(['purpose' => 'booking']);
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('purpose', self::CURRENT)->default('booking')->change();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hall_booking_id');
        });
        Schema::dropIfExists('hall_bookings');
    }
};
