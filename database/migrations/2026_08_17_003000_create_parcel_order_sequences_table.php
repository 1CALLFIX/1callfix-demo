<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase 22.4 (Parcel). A direct mirror of `booking_sequences` -- Parcel
// gets its OWN atomic per-franchise-per-day counter, deliberately separate
// from Service's, so Parcel order codes are visibly distinct (PCL segment,
// see OrderCodeService::generateForParcel()) and never share a sequence
// with Service bookings. Existing Service booking numbering is completely
// untouched.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcel_order_sequences', function (Blueprint $table) {
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
        Schema::dropIfExists('parcel_order_sequences');
    }
};
