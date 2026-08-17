<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HOTEL / STAY BOOKING MODULE -- multi-room line items. A single
 * `hotel_reservations` row (one customer reservation, one check-in/check-
 * out date range) can carry MULTIPLE rows here -- e.g. 2x Deluxe Room
 * (Breakfast Included rate plan) + 1x Suite (Flexible rate plan) -- per the
 * mission brief's own explicit "a single customer reservation may contain
 * multiple room allocations" requirement.
 *
 * `nightly_rate_snapshot`/`room_count`/`line_total` freeze the price at
 * booking time (the rate plan's own `nightly_rate` can change later without
 * silently altering a past reservation's price) -- same "snapshot the price
 * at order time" convention every other order-line concept in this
 * codebase already uses (e.g. `bookings.price_quoted` itself, not a live
 * join to `services.base_price`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_reservation_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotel_room_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('hotel_rate_plan_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('room_count')->default(1);
            $table->decimal('nightly_rate_snapshot', 10, 2);
            $table->decimal('line_total', 10, 2);

            $table->timestamps();

            $table->index('hotel_reservation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_reservation_rooms');
    }
};
