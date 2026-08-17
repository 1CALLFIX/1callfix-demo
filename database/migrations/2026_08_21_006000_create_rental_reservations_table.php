<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RENTAL MODULE IMPLEMENTATION -- the shared Rental Engine reservation
 * table for Vehicle and Equipment (the two genuinely new rental types this
 * phase builds). Property Rental keeps its own existing, proven
 * `property_reservations` engine completely UNCHANGED -- Property's
 * per-night calendar model (sparse `property_availabilities` rows) and
 * Vehicle/Equipment's per-range hourly-capable model are real, different
 * concurrency shapes (see RentalAvailabilityService's own docblock), so
 * unifying them into one polymorphic table would either force Property
 * onto a model it doesn't need or force Vehicle/Equipment onto a per-day
 * granularity that breaks hourly rentals. This is the deliberate
 * "different specialized rules without duplicating the entire engine"
 * split the brief asks for: ONE new shared engine for the two new types
 * (this table), Property's own proven engine reused as-is for the third.
 *
 * `rentable_type`/`rentable_id` -- a real polymorphic relation (morphs to
 * `Vehicle` or `EquipmentItem`), not a fixed FK to either, so a future
 * fourth rental_type can plug into this same engine without a schema
 * change (the brief's own "leave room" instruction, applied to the engine
 * itself, not just rental modes).
 *
 * `rental_type` (vehicle|equipment) is a plain discriminator column,
 * matching the `stores.module`/`marketplace_orders.module` precedent
 * (Phase 24) for "one shared engine, several sub-verticals" -- not a
 * second source of truth for `rentable_type` (that's Eloquent's own
 * morph-map string), just a fast, indexable filter column exactly like
 * Marketplace's own `module` column is redundant-but-useful for reporting.
 *
 * `status` (pending -> confirmed -> picked_up -> active -> returned ->
 * completed, cancelled from any non-terminal state) is ONE shared enum
 * covering all three flows the brief gives, each type/mode using the
 * subset it needs -- not three separate FSMs:
 *   Self-Drive:   pending -> confirmed -> picked_up -> active -> returned -> completed
 *   With Driver:  pending -> confirmed -> active -> completed (skips picked_up/returned -- no physical customer pickup/return of a vehicle someone else drives)
 *   Equipment:    pending -> confirmed -> picked_up -> active -> returned -> completed (+ inspected_at/inspection_notes when the category requires it)
 * Every status value here is one the brief explicitly names for at least
 * one flow -- no extra states invented.
 *
 * `rental_mode` is a plain nullable string (self_drive/with_driver for
 * Vehicle, always null for Equipment) -- not an enum -- so a future mode
 * doesn't need a migration, matching `vehicles.supported_rental_modes`'s
 * own extensibility reasoning.
 *
 * `driver_field_worker_id` -- With Driver rentals need "association of an
 * appropriate driver where the existing architecture permits" (brief's own
 * words). `FieldWorker` is the existing architecture's real driver-shaped
 * entity (Taxi's own `assignedWorker`, confirmed via TaxiRide), reused
 * here rather than inventing a new Driver table. Deliberately just an
 * association column -- no dispatch/matching logic is built on top of it
 * (that would be building the Taxi engine's job-matching machinery for a
 * product that isn't Taxi, which the brief explicitly forbids).
 *
 * `starts_at`/`ends_at` are DATETIME (not DATE like `property_reservations
 * .check_in_date`) -- required for hourly-unit rentals to be meaningful.
 *
 * Deposit fields are a configurable MECHANISM only -- no amount, refund
 * timing, or forfeiture rule is decided here (see KNOWN_RISKS_AND_
 * DECISIONS entry this phase). `deposit_status` starts at `not_collected`
 * and is only ever moved by an explicit admin action recording a real-
 * world outcome, never inferred.
 *
 * Same column shape (code, franchise, zone, customer, status, price_quoted,
 * price_final, payment_status, payment_method, cancellation fields,
 * timestamps, soft deletes) as `property_reservations` wherever the concept is shared,
 * for the same reasons that table already documents -- consistency with
 * the codebase's own established Orderable-implementer shape, not a new
 * convention invented for this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();

            $table->enum('rental_type', ['vehicle', 'equipment']);
            $table->morphs('rentable'); // rentable_type, rentable_id -- Vehicle or EquipmentItem

            $table->string('rental_mode')->nullable(); // self_drive|with_driver (vehicle only), extensible, see docblock
            $table->foreignId('driver_field_worker_id')->nullable()->constrained('field_workers')->nullOnDelete();

            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->enum('duration_unit', ['hourly', 'daily', 'weekly', 'monthly']);
            $table->decimal('duration_quantity', 8, 2);

            $table->enum('status', ['pending', 'confirmed', 'picked_up', 'active', 'returned', 'completed', 'cancelled'])->default('pending');

            $table->decimal('price_quoted', 10, 2);
            $table->decimal('price_final', 10, 2)->nullable();
            $table->enum('payment_status', ['pending', 'paid', 'refunded', 'partially_refunded'])->default('pending');
            $table->string('payment_method')->nullable();

            $table->decimal('deposit_amount', 10, 2)->nullable();
            $table->enum('deposit_status', ['not_collected', 'held', 'refunded', 'forfeited'])->default('not_collected');
            $table->string('deposit_note')->nullable();

            $table->foreignId('cancellation_reason_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cancellation_note')->nullable();
            $table->decimal('cancellation_fee', 10, 2)->nullable();

            $table->text('special_requests')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->text('inspection_notes')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index(['franchise_id', 'zone_id']);
            $table->index(['rentable_type', 'rentable_id', 'starts_at', 'ends_at'], 'rental_reservations_rentable_range_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_reservations');
    }
};
