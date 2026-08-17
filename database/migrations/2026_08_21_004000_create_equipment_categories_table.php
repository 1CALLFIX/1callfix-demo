<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RENTAL MODULE IMPLEMENTATION -- Equipment/Machinery domain. Same shape
 * as `vehicle_categories`/`property_types`. Initial categories per the
 * mission brief: construction, machinery, tools, generators, agricultural,
 * lifting, other -- created via admin/fixtures, not seeded by this
 * migration (same "no seeded rows" convention as property_types/
 * vehicle_categories).
 *
 * `requires_inspection` records whether this category's rentals need a
 * post-return inspection step before completion (the brief's own
 * "inspection where required" -- a real, per-category, admin-configurable
 * flag, not a blanket policy invented for every equipment item).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->boolean('requires_inspection')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_categories');
    }
};
