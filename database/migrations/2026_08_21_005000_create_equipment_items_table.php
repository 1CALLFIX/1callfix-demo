<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RENTAL MODULE IMPLEMENTATION -- Equipment/Machinery inventory (the
 * listing/catalog; the order is the shared `rental_reservations` table).
 * Same owner-reuse (`provider_id` -> Provider), same single-duration-unit
 * pricing, same "no invented rates/deposit values" reasoning as
 * `vehicles` -- see that migration's own docblock for the shared rationale,
 * not repeated here.
 *
 * `condition`/`operating_hours`/`accessories`/`specifications` are the
 * extensible-attribute set the brief asks for. `condition` is a plain
 * nullable string (not an enum) -- no evidence of a fixed, exhaustive
 * condition taxonomy was given. `operating_hours` is nullable (only
 * "where applicable" per the brief -- a hand tool has no meter reading).
 * `accessories` is a nullable text field (free-form list) rather than a
 * new pivot table -- no evidence a structured, individually-priced
 * accessory catalog was asked for; a real pivot can be added additively
 * later if that need is confirmed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->text('specifications')->nullable();
            $table->string('condition')->nullable();
            $table->unsignedInteger('operating_hours')->nullable();
            $table->text('accessories')->nullable();
            $table->string('maintenance_state')->nullable();

            $table->decimal('base_price', 10, 2);
            $table->decimal('discount_price', 10, 2)->nullable();
            $table->enum('pricing_unit', ['hourly', 'daily', 'weekly', 'monthly'])->default('daily');
            $table->decimal('deposit_amount', 10, 2)->nullable();

            $table->string('location_line')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->text('rental_terms')->nullable();
            $table->string('cover_image')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['franchise_id', 'zone_id']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_items');
    }
};
