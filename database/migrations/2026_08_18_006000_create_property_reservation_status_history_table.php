<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase 22.7 (Property Rental) -- direct mirror of parcel_order_status_history/
// taxi_ride_status_history's own shape.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_reservation_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_reservation_id')->constrained(indexName: 'prop_res_status_history_fk')->cascadeOnDelete();
            $table->string('status');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_reservation_status_history');
    }
};
