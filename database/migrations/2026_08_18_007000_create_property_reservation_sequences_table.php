<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase 22.7 (Property Rental) -- own atomic per-franchise-per-day counter,
// same reasoning as parcel_order_sequences/taxi_ride_sequences (-PRP-
// code segment, never shares a sequence with any other vertical).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_reservation_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->date('sequence_date');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['franchise_id', 'sequence_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_reservation_sequences');
    }
};
