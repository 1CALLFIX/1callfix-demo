<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HOTEL / STAY BOOKING MODULE -- the concurrency-safety inventory table,
 * the room-type-quantity counterpart to `property_availabilities`. One row
 * per room type per calendar day; absence of a row means "fully available"
 * (the same sparse-default model `property_availabilities` established),
 * but UNLIKE that boolean table, `rooms_booked` is a running COUNT --
 * `hotel_room_types.total_inventory - rooms_booked` is the real number of
 * rooms of this type still bookable on this date. This is the real
 * difference the mission brief's own "prevent overbooking" requirement
 * demands: a whole-property reservation only ever needs a boolean, a
 * multi-room hotel booking needs a quantity.
 *
 * `is_available` is a SEPARATE manual full-block flag (admin takes every
 * room of this type off sale for a date -- maintenance, a block booking
 * outside this system, etc.), independent of `rooms_booked` -- a date can
 * have `rooms_booked = 0` and still be `is_available = false`.
 *
 * Concurrency safety (`HotelAvailabilityService`) uses the SAME two-part
 * design `PropertyAvailabilityService` already established: `lockForUpdate()`
 * any EXISTING rows in the requested range (serializes concurrent access
 * once a row exists), plus this table's own `unique(['hotel_room_type_id',
 * 'date'])` constraint as the real backstop for the case where NEITHER
 * concurrent transaction sees a pre-existing row yet (nothing to lock) --
 * the database itself rejects the second first-touch INSERT for that date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_room_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_room_type_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            $table->unsignedInteger('rooms_booked')->default(0);
            $table->boolean('is_available')->default(true);
            $table->decimal('price_override', 10, 2)->nullable();
            $table->enum('reason', ['available', 'blocked', 'maintenance'])->default('available');

            $table->timestamps();

            $table->unique(['hotel_room_type_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_room_availabilities');
    }
};
