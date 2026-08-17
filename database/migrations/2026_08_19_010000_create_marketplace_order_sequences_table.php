<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase 24 (Marketplace Foundation) -- own atomic per-franchise-per-day
// counter, same reasoning as parcel_order_sequences/taxi_ride_sequences/
// property_reservation_sequences (-MKT- code segment, shared across
// Ecommerce/Food/Grocery/Pharmacy per the shared-order-table decision,
// never shares a sequence with any OTHER vertical family).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_order_sequences', function (Blueprint $table) {
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
        Schema::dropIfExists('marketplace_order_sequences');
    }
};
