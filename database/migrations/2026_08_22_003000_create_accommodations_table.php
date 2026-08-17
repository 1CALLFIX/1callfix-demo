<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HOTEL / STAY BOOKING MODULE. The listing (catalog-shaped, like `Property`/
 * `Service`) — `HotelReservation` is the order. `provider_id`, not a new
 * "Vendor" entity — same reasoning `properties`' own migration gives: an
 * accommodation owner is exactly the accountable-business/individual
 * concept `Provider` already models.
 *
 * Deliberately NO nightly base price column here, unlike `properties.
 * base_price` — a Hotel/Stay listing's actual sellable price lives on
 * `hotel_rate_plans` (room-type + rate-plan combination), per the mission
 * brief's own explicit architecture (Accommodation -> Room Type -> Room
 * Inventory -> Rate Plan -> Availability -> Reservation). An Accommodation
 * with no room types/rate plans yet is a real, valid, simply-not-bookable
 * state — same as a `Property` with `is_active=false`.
 *
 * `check_in_time_start`/`check_in_time_end`/`check_out_time` use the exact
 * same neutral technical defaults `properties` already shipped with
 * (14:00/22:00/11:00) — real established precedent in THIS codebase for
 * the closest analogous entity, not a fresh invention; per-accommodation
 * editable, never a business decision baked in as immutable.
 *
 * No `cancellation_policy_id` column, same established precedent as
 * `properties` (`CancellationService::calculateFeeForHotelReservation`
 * reads Setting-driven policy values instead — see that method).
 *
 * Ships fully inert: `modules.is_implemented` for `hotel` stays `false`
 * (Phase 22.1's hard gate) until a real deployment decision flips it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accommodation_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->string('address_line');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);

            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();

            $table->time('check_in_time_start')->default('14:00:00');
            $table->time('check_in_time_end')->default('22:00:00');
            $table->time('check_out_time')->default('11:00:00');

            $table->text('policies')->nullable();
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
        Schema::dropIfExists('accommodations');
    }
};
