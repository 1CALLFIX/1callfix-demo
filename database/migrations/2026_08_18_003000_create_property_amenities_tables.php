<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase 22.7 (Property Rental). Verified against Glover's real `Amenity`/
// `amenity_property` pivot. A minimal, standard many-to-many — real
// evidence, cheap to add, not core to the reservation lifecycle so kept
// deliberately small (name/icon only, no per-amenity business logic).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_amenities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('icon')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('property_amenity_property', function (Blueprint $table) {
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_amenity_id')->constrained()->cascadeOnDelete();
            $table->primary(['property_id', 'property_amenity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_amenity_property');
        Schema::dropIfExists('property_amenities');
    }
};
