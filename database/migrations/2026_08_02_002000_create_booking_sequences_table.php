<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Atomic per-franchise, per-day counter used to generate booking codes like NLR-2907-00000001.
// Kept as its own table (not computed from bookings.count()) specifically to make the
// increment race-condition-safe under concurrent booking creation.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->date('sequence_date');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['franchise_id', 'sequence_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_sequences');
    }
};
