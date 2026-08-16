<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22.7 (Property Rental). Verified against Glover's real
 * `PropertyType` model/migration directly (D:\D-Downloads\version-1.8.50\
 * references\glover-1.8.5). A small, dedicated taxonomy (Apartment/Villa/
 * Studio/...) — deliberately NOT reusing `service_categories`: that table
 * carries baggage (parent_id, sort_order, color, icon, module-tag
 * filtering wired into Categories/Subcategories/Services/Banners screens)
 * that doesn't belong to a genuinely different catalog axis, and Property
 * Rental needing its own small table costs nothing (same reasoning
 * `parcel_order_sequences`/`taxi_ride_sequences` used for "small,
 * cheap-to-duplicate, no real repetition to abstract").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_types');
    }
};
