<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// RENTAL MODULE IMPLEMENTATION -- own atomic per-franchise-per-day counter
// for the shared Vehicle/Equipment engine, same shape as
// property_reservation_sequences/marketplace_order_sequences. ONE shared
// counter across both rental_type values (vehicle/equipment), same
// reasoning marketplace_order_sequences shares one counter across four
// verticals -- the code segment (VEH/EQP, decided per-reservation in
// OrderCodeService::generateForRentalReservation()) is what disambiguates
// the type, not a second counter table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_reservation_sequences', function (Blueprint $table) {
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
        Schema::dropIfExists('rental_reservation_sequences');
    }
};
