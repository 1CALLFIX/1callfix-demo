<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22.7 (Property Rental) — the fourth real Orderable implementer.
 * Own dedicated table (Option A, Phase 22.2), not a row in `bookings`/
 * `parcel_orders`/`taxi_rides`. Column shapes for guests/nights/dates
 * verified against Glover's real `booking_orders` table; unlike Glover
 * (whose `booking_orders` hangs off a shared, wide `orders` table with
 * dozens of other verticals' columns bled into it — confirmed by direct
 * read, e.g. `package_type_id`/`weight`/`delivery_address_id` all live on
 * the SAME `orders` table Property reservations use there), this table
 * carries its own franchise/zone/customer/price/status columns directly,
 * exactly like `parcel_orders`/`taxi_rides` before it.
 *
 * `status` reflects the mission's own given conceptual flow (RESERVATION
 * → PAYMENT → CONFIRMATION → STAY → COMPLETION) rather than Glover's own
 * evidence, which was genuinely thin here (no clean status enum was found
 * on `booking_orders` or the generic `orders` table it depends on) —
 * this codebase's own now-three-times-proven FSM shape (Booking/
 * ParcelOrder/TaxiRide: pending → active → completed/cancelled) is the
 * more relevant precedent for what a fourth 1CallFix vertical's status
 * enum should look like.
 *
 * `booking_type` (room vs. entire_property, real Glover evidence) is
 * deliberately NOT included — no room-level sub-inventory concept exists
 * anywhere in this schema; every property here is booked as a whole,
 * matching `is_host_residing`'s absence too (no shared-space concept
 * evidenced or built).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();

            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->unsignedInteger('number_of_nights');
            $table->unsignedInteger('number_of_guests')->default(1);

            $table->enum('status', ['pending', 'confirmed', 'checked_in', 'completed', 'cancelled'])->default('pending');

            $table->decimal('price_quoted', 10, 2);
            $table->decimal('price_final', 10, 2)->nullable();
            $table->enum('payment_status', ['pending', 'paid', 'refunded', 'partially_refunded'])->default('pending');
            $table->string('payment_method')->nullable();

            $table->foreignId('cancellation_reason_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cancellation_note')->nullable();
            $table->decimal('cancellation_fee', 10, 2)->nullable();

            $table->text('special_requests')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index(['franchise_id', 'zone_id']);
            $table->index(['property_id', 'check_in_date', 'check_out_date'], 'property_res_dates_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_reservations');
    }
};
