<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase 22.6 (Taxi) -- direct mirror of parcel_order_status_history's own
// shape (itself a mirror of booking_status_history), same reasoning: a
// proven, single-purpose table, cheap to duplicate, no new invention.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxi_ride_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taxi_ride_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxi_ride_status_history');
    }
};
