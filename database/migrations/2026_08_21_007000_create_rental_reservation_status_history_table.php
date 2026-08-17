<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// RENTAL MODULE IMPLEMENTATION -- direct mirror of
// property_reservation_status_history/parcel_order_status_history/
// taxi_ride_status_history's own shape, for the shared Vehicle/Equipment
// engine.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_reservation_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_reservation_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_reservation_status_history');
    }
};
