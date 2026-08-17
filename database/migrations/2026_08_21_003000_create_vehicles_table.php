<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RENTAL MODULE IMPLEMENTATION -- Vehicle inventory (the listing/catalog,
 * NOT the order -- exact same catalog/order split `properties` /
 * `property_reservations` already established; the order lives in the new
 * shared `rental_reservations` table, see its own migration).
 *
 * `provider_id` reuses `Provider` as the owner, same identity-reuse
 * pattern `properties.provider_id` and `stores.provider_id` already use --
 * no new Vendor/Owner table invented.
 *
 * `supported_rental_modes` (json array, e.g. ["self_drive","with_driver"])
 * is what makes Self-Drive / With Driver real domain values instead of UI
 * labels, per the mission brief -- a per-vehicle allow-list, deliberately
 * an open array (not an enum/pivot to a fixed mode table) so a future
 * rental mode can be added without a schema change, per the brief's own
 * "leave room for future rental modes without hardcoding" instruction.
 * `rental_reservations.rental_mode` (own migration) is validated against
 * this list at reservation-creation time, not hardcoded to two values.
 *
 * `fuel_type`/`transmission` are plain nullable strings, not enums --
 * evidence-free of a final exhaustive list (electric/hybrid/CNG variants
 * exist; forcing an enum here would be inventing a business taxonomy that
 * wasn't asked for).
 *
 * `pricing_unit` follows the brief's explicit instruction ("do not force
 * every inventory item to support every [duration] unit") -- ONE unit per
 * vehicle, chosen by whoever lists it, not a many-to-many rate table (no
 * evidence multi-unit pricing per item was asked for; a configurable
 * mechanism, not an invented one).
 *
 * `base_price`/`discount_price`/`deposit_amount` are configurable fields
 * the owner/admin sets -- no rate, discount, or deposit VALUE is invented
 * or defaulted to a non-zero number anywhere in this migration or the
 * Actions built on top of it (see KNOWN_RISKS_AND_DECISIONS.md entry this
 * phase for the explicit business-decisions-not-made list).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();

            $table->string('make');
            $table->string('model');
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('fuel_type')->nullable();
            $table->string('transmission')->nullable();
            $table->unsignedInteger('seating_capacity')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('color')->nullable();

            // Extensible allow-list of rental modes this vehicle offers --
            // see class docblock. Defaults to self_drive only (the more
            // universally-applicable mode per the given taxonomy: every
            // vehicle type supports Self-Drive, only Car explicitly also
            // supports With Driver).
            $table->json('supported_rental_modes')->default(json_encode(['self_drive']));

            $table->decimal('base_price', 10, 2);
            $table->decimal('discount_price', 10, 2)->nullable();
            $table->enum('pricing_unit', ['hourly', 'daily', 'weekly', 'monthly'])->default('daily');
            $table->decimal('deposit_amount', 10, 2)->nullable();

            $table->string('pickup_location_line')->nullable();
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
        Schema::dropIfExists('vehicles');
    }
};
