<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22.4 (Parcel) — CommissionService generalization, decided by
 * building Parcel (the second real Orderable implementer), per Phase 22.2's
 * own plan. `commissions` gets a plain nullable `parcel_order_id` column
 * alongside the existing `booking_id` — NOT full polymorphism, matching
 * this codebase's own established precedent of a plain nullable FK when
 * only two concrete types exist (e.g. Payout.payee_type/payee_id uses a
 * string discriminator + two possible FK targets, not a morph column).
 *
 * `booking_id` is loosened from NOT NULL to nullable so a parcel-order
 * commission row (which has no booking) can exist — every EXISTING row
 * already has a real, non-null booking_id, so this is purely additive:
 * nothing about any existing commission row changes. The existing
 * `commissions_booking_id_unique` constraint is untouched (SQL treats
 * multiple NULLs as distinct for a UNIQUE index on every DB this app runs
 * on, so this doesn't block more than one future parcel-only row from
 * existing alongside it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->foreignId('booking_id')->nullable()->change();
            $table->foreignId('parcel_order_id')->nullable()->after('booking_id')->constrained()->nullOnDelete();
            $table->unique('parcel_order_id', 'commissions_parcel_order_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropUnique('commissions_parcel_order_id_unique');
            $table->dropConstrainedForeignId('parcel_order_id');
            $table->foreignId('booking_id')->nullable(false)->change();
        });
    }
};
