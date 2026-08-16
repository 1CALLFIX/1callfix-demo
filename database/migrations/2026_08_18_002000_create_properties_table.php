<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22.7 (Property Rental). Column shapes verified directly against
 * Glover's real `properties` migration — kept the fields with real
 * evidence (guest/room counts, check-in/out windows, min/max nights,
 * house rules), dropped Glover's free-text city/state/country/postal_code
 * (redundant here: `franchise_id`/`zone_id` already encode geography for
 * every 1CallFix table, unlike Glover, which has no franchise concept at
 * all). `provider_id`, not a new "Vendor" entity — a property owner is
 * exactly the accountable-business/individual concept `Provider` already
 * models; this is NOT the marketplace-Vendor question `PHASE_22_8_
 * MARKETPLACE_VENDOR_ARCHITECTURE_DECISION.md` addresses.
 *
 * No `cancellation_policy_id` column — verified against this codebase's
 * own `cancellation_policies` table, which is real schema but DELIBERATELY
 * UNUSED (migration `2026_08_11_0XXXXX_seed_cancellation_policy_defaults`'s
 * own docblock: routes cancellation policy through `Setting::get()`
 * instead, "do not create another settings architecture"). Property
 * Rental follows that same established precedent — see
 * `CancellationService::calculateFeeForPropertyReservation()`.
 *
 * Ships fully inert: `modules.is_implemented` for `car_rental` stays
 * `false` (Phase 22.1's hard gate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('address_line');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);

            $table->decimal('base_price', 10, 2); // per night
            $table->decimal('discount_price', 10, 2)->nullable();

            $table->unsignedInteger('max_guests')->default(1);
            $table->unsignedInteger('bedrooms')->default(1);
            $table->unsignedInteger('bathrooms')->default(1);
            $table->unsignedInteger('beds')->default(1);

            $table->time('check_in_time_start')->default('14:00:00');
            $table->time('check_in_time_end')->default('22:00:00');
            $table->time('check_out_time')->default('11:00:00');
            $table->unsignedInteger('minimum_nights')->default(1);
            $table->unsignedInteger('maximum_nights')->nullable();

            $table->text('house_rules')->nullable();
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
        Schema::dropIfExists('properties');
    }
};
