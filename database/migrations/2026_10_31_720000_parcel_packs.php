<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parcel Stage 2 — prepaid parcel packs for businesses (owner decision 2026-10-31). Super Admin creates packs
 * ("50 parcels within 5 km for ₹X, valid 30 days"); a customer buys one ONLINE (Razorpay or wallet); each parcel
 * uses one credit, which covers the base fare + distance up to the pack's km; anything above is paid online.
 * A pack credit is a benefit (THUMB RULE): a parcel that uses one can never be paid in cash.
 *
 * payments.purpose is WIDENED to add 'parcel_pack'. Before migrating production, read the live enum
 * (SHOW COLUMNS FROM payments LIKE 'purpose') and confirm it equals CURRENT below in full.
 */
return new class extends Migration
{
    private const CURRENT = [
        'booking', 'wallet_topup', 'plan_subscription', 'parcel_order', 'taxi_ride',
        'property_reservation', 'marketplace_order', 'rental_reservation', 'hotel_reservation',
        'booking_bundle', 'cancellation_fee',
    ];

    public function up(): void
    {
        Schema::create('parcel_packs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('franchise_id')->nullable()->constrained()->nullOnDelete(); // null = every franchise
            $table->unsignedSmallInteger('parcel_count');
            $table->decimal('max_km', 6, 2); // a credit covers base fare + distance up to this many km
            $table->decimal('price', 10, 2);
            $table->unsignedSmallInteger('validity_days');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('parcel_pack_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parcel_pack_id')->constrained('parcel_packs');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('franchise_id')->nullable()->constrained()->nullOnDelete();
            // Snapshot of the pack at purchase: later edits to the pack never change what was bought.
            $table->string('name', 120);
            $table->unsignedSmallInteger('parcels_total');
            $table->unsignedSmallInteger('parcels_used')->default(0);
            $table->decimal('max_km', 6, 2);
            $table->decimal('price_paid', 10, 2);
            $table->unsignedSmallInteger('validity_days');
            $table->string('status', 20)->default('pending_payment'); // pending_payment | active | cancelled
            $table->string('payment_method', 20)->nullable(); // online | wallet
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'expires_at']);
        });

        Schema::table('parcel_orders', function (Blueprint $table) {
            $table->foreignId('parcel_pack_purchase_id')->nullable()->after('protection_fee')->constrained('parcel_pack_purchases')->nullOnDelete();
            $table->decimal('pack_covered', 10, 2)->nullable()->after('parcel_pack_purchase_id');
            $table->dateTime('pack_credit_returned_at')->nullable()->after('pack_covered');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('parcel_pack_purchase_id')->nullable()->after('parcel_order_id')->constrained('parcel_pack_purchases')->nullOnDelete();
            $table->enum('purpose', [...self::CURRENT, 'parcel_pack'])->default('booking')->change();
        });
    }

    public function down(): void
    {
        // REFUSE rather than rewrite real payment rows to a different purpose.
        $rows = DB::table('payments')->where('purpose', 'parcel_pack')->count();
        if ($rows > 0) {
            throw new \RuntimeException("Cannot roll back: {$rows} payment(s) have purpose 'parcel_pack'. Resolve them deliberately first.");
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parcel_pack_purchase_id');
            $table->enum('purpose', self::CURRENT)->default('booking')->change();
        });

        Schema::table('parcel_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parcel_pack_purchase_id');
            $table->dropColumn(['pack_covered', 'pack_credit_returned_at']);
        });

        Schema::dropIfExists('parcel_pack_purchases');
        Schema::dropIfExists('parcel_packs');
    }
};
