<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// HOTEL / STAY BOOKING MODULE -- own atomic per-franchise-per-day counter,
// same reasoning as property_reservation_sequences/rental_reservation_sequences
// (-HTL- code segment, never shares a sequence with any other vertical).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_reservation_sequences', function (Blueprint $table) {
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
        Schema::dropIfExists('hotel_reservation_sequences');
    }
};
