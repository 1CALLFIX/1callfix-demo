<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HOTEL / STAY BOOKING MODULE -- the order (`Orderable`), own dedicated
 * table, same "own table per vertical" precedent as `parcel_orders`/
 * `taxi_rides`/`property_reservations`/`rental_reservations`. This is the
 * reservation HEADER; `hotel_reservation_rooms` (own migration) carries the
 * per-room-type/rate-plan line items that make multi-room bookings real
 * (one reservation, 2 Deluxe + 1 Suite, is two rows there under one row
 * here) -- `number_of_rooms` here is a denormalized total for cheap list
 * display, the line items are the source of truth for what was actually
 * booked/priced.
 *
 * `status` implements the mission brief's own explicit lifecycle (pending
 * -> confirmed -> checked_in -> checked_out -> cancelled -> completed) --
 * deliberately NOT Property Rental's shorter checked_in -> completed
 * shape: a hotel stay has a real, distinct checkout moment (the guest
 * leaves, but the stay isn't administratively finalized/commissioned
 * until a separate completion step), which the brief itself calls out as
 * different from Rental's pickup/return workflow.
 *
 * `number_of_adults`/`number_of_children` are the reservation-level
 * totals; per-guest names/details live on `hotel_guests` (own migration) --
 * booking customer (`customer_id`) vs actual guests are deliberately
 * separate concepts, per the mission brief.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('accommodation_id')->constrained()->cascadeOnDelete();

            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->unsignedInteger('number_of_nights');
            $table->unsignedInteger('number_of_rooms')->default(1);
            $table->unsignedInteger('number_of_adults')->default(1);
            $table->unsignedInteger('number_of_children')->default(0);

            $table->enum('status', ['pending', 'confirmed', 'checked_in', 'checked_out', 'completed', 'cancelled'])->default('pending');

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
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index(['franchise_id', 'zone_id']);
            $table->index(['accommodation_id', 'check_in_date', 'check_out_date'], 'hotel_res_accom_dates_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_reservations');
    }
};
