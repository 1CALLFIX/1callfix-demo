<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HOTEL / STAY BOOKING MODULE. Guest information, deliberately separate
 * from the booking owner (`hotel_reservations.customer_id`) -- the mission
 * brief's own explicit "Booking Customer vs actual Guests" requirement.
 * `name`/`phone`/`email` are all nullable except `name` -- no mandatory
 * identity/KYC rule is invented here (the brief explicitly forbids that);
 * a reservation can be created with only adult/child COUNTS and no named
 * guests at all, matching `hotel_reservations.number_of_adults`/
 * `number_of_children` already being sufficient for pricing/capacity on
 * their own. Named-guest rows are an optional richer layer on top.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_reservation_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->enum('guest_type', ['adult', 'child'])->default('adult');
            $table->boolean('is_primary')->default(false);
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            $table->timestamps();

            $table->index('hotel_reservation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_guests');
    }
};
