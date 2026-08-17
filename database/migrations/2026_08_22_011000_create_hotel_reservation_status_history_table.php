<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// HOTEL / STAY BOOKING MODULE -- direct mirror of
// property_reservation_status_history/rental_reservation_status_history's own shape.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_reservation_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_reservation_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_reservation_status_history');
    }
};
