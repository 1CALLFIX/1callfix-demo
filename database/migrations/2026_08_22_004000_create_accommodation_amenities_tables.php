<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// HOTEL / STAY BOOKING MODULE. Same minimal many-to-many pattern as
// `property_amenities`/`property_amenity_property` -- name/icon only, no
// per-amenity business logic, kept deliberately small.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodation_amenities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('icon')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('accommodation_amenity_accommodation', function (Blueprint $table) {
            $table->foreignId('accommodation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accommodation_amenity_id')->constrained()->cascadeOnDelete();
            $table->primary(['accommodation_id', 'accommodation_amenity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_amenity_accommodation');
        Schema::dropIfExists('accommodation_amenities');
    }
};
