<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Events E2 (owner 2026-11-13): customers buy tickets online.
 * - event_orders: one purchase (online or wallet; no cash, ever). No payment, no ticket: an online order holds its
 *   tickets for Event Settings' unpaid hold, then they go back on sale.
 * - event_order_items: the ticket types and quantities bought, prices as charged.
 * - event_tickets: one row per ticket, with its own code for the QR shown at the gate (used once).
 * - payments.event_order_id and purpose 'event_order' (the Razorpay capture webhook confirms the order).
 * - modules.events is_implemented = true: an admin may now switch Events on per city (Admin → Modules).
 *
 * payments.purpose before this migration — read from production with SHOW COLUMNS on 2026-11-13, all 12 values:
 * booking, wallet_topup, plan_subscription, parcel_order, taxi_ride, property_reservation, marketplace_order,
 * rental_reservation, hotel_reservation, booking_bundle, cancellation_fee, parcel_pack.
 */
return new class extends Migration
{
    private const CURRENT = [
        'booking', 'wallet_topup', 'plan_subscription', 'parcel_order', 'taxi_ride', 'property_reservation',
        'marketplace_order', 'rental_reservation', 'hotel_reservation', 'booking_bundle', 'cancellation_fee', 'parcel_pack',
    ];

    public function up(): void
    {
        Schema::create('event_orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('buyer_name', 120);
            $table->string('buyer_phone', 20)->nullable();
            // pending (waiting for payment) → confirmed (paid, tickets issued) | cancelled (not paid in time / event cancelled)
            $table->string('status', 20)->default('pending');
            $table->string('payment_method', 20); // online | wallet
            $table->string('payment_status', 20)->default('pending'); // pending | paid | refunded
            $table->unsignedInteger('ticket_count');
            $table->decimal('subtotal', 10, 2);
            $table->decimal('booking_fee', 10, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->boolean('platform_collects_gst')->default(true);
            $table->decimal('total', 10, 2);
            $table->decimal('refunded_amount', 10, 2)->default(0);
            $table->timestamp('payment_expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('cancellation_note', 255)->nullable();
            $table->timestamps();
            $table->index(['status', 'payment_status', 'payment_expires_at']);
            $table->index(['customer_id', 'created_at']);
        });

        Schema::create('event_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_ticket_type_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('gst_percent', 5, 2)->default(0);
            $table->unsignedInteger('quantity');
            $table->unsignedTinyInteger('admits')->default(1);
            $table->decimal('line_total', 10, 2); // unit_price × quantity, before GST and fee
            $table->timestamps();
        });

        Schema::create('event_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_ticket_type_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40)->unique(); // shown as the QR; checked once at the gate
            $table->unsignedTinyInteger('admits')->default(1);
            $table->string('status', 20)->default('valid'); // valid | used | cancelled
            $table->timestamp('checked_in_at')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['event_id', 'status']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('event_order_id')->nullable()->after('hotel_reservation_id')->constrained()->nullOnDelete();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('purpose', [...self::CURRENT, 'event_order'])->default('booking')->change();
        });

        DB::table('modules')->where('code', 'events')->update(['is_implemented' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('modules')->where('code', 'events')->update(['is_implemented' => false, 'updated_at' => now()]);
        DB::table('payments')->where('purpose', 'event_order')->update(['purpose' => 'booking']);
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('purpose', self::CURRENT)->default('booking')->change();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_order_id');
        });
        Schema::dropIfExists('event_tickets');
        Schema::dropIfExists('event_order_items');
        Schema::dropIfExists('event_orders');
    }
};
