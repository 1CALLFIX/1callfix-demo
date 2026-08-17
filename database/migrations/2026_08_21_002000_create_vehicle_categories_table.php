<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RENTAL MODULE IMPLEMENTATION -- Vehicle domain, first migration.
 *
 * Same shape/reasoning as `property_types` (Phase 22.7): a small, dedicated
 * taxonomy table, not a row in `service_categories` (which carries
 * module-tag/dropdown baggage that doesn't belong to a different catalog
 * axis). Mirrors the mission brief's own given taxonomy exactly:
 *
 *   Vehicle
 *   +-- Car (Self-Drive, With Driver)
 *   +-- Bike (Self-Drive)
 *   +-- Scooter
 *   +-- Van
 *   +-- Other Vehicle
 *
 * `slug` is the stable machine key (car/bike/scooter/van/other); rows are
 * created via the admin panel (or test fixtures), not seeded here -- same
 * convention `property_types` already established (no seeder exists for
 * it either). No row is inserted by this migration.
 *
 * Self-Drive / With Driver are NOT columns on this table -- they are real
 * rental modes recorded per VEHICLE (`vehicles.supported_rental_modes`,
 * next migration) and per RESERVATION (`rental_reservations.rental_mode`),
 * because which modes a category *could* support (e.g. Bike is Self-Drive
 * only per the given taxonomy) is a different question from which modes a
 * specific vehicle actually offers -- keeping the mode list on the
 * category would either force every bike row to redundantly repeat it or
 * silently couple category to mode in a way nothing in the brief asks for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_categories', function (Blueprint $table) {
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
        Schema::dropIfExists('vehicle_categories');
    }
};
