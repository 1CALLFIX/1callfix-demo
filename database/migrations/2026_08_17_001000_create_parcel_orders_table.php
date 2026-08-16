<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22.4 (Parcel). Per the Option A decision (PHASE_22_2_ORDER_ENGINE_
 * ARCHITECTURE_DECISION.md), Parcel gets its OWN order table, not a row in
 * `bookings` — `bookings.service_id` stays exactly as it is, untouched.
 * Column shapes deliberately mirror `bookings`' own proven conventions
 * (status enum, price_quoted/price_final, payment_status/payment_method,
 * two OTPs instead of one, cancellation fields) rather than inventing a
 * different vocabulary — see PHASE_22_4_PARCEL_DESIGN.md for the full
 * reasoning per field.
 *
 * Ships fully inert: `modules.is_implemented` for `parcel` stays `false`
 * (Phase 22.1's hard gate) — this table existing does not make Parcel
 * usable by a single real customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcel_orders', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();

            // Both FK to the existing Address model -- reused as-is, no new
            // address concept needed (PHASE_22_4_PARCEL_DESIGN.md).
            $table->foreignId('pickup_address_id')->constrained('addresses')->cascadeOnDelete();
            $table->foreignId('dropoff_address_id')->constrained('addresses')->cascadeOnDelete();

            // The worker who accepted this order -- a FieldWorker holding
            // the pre-existing 'parcel_rider' capability (App\Support\
            // WorkerTypes, already seeded, not a new capability type).
            $table->foreignId('assigned_worker_id')->nullable()->constrained('field_workers')->nullOnDelete();

            // Package details -- deliberately minimal. package_size is a
            // free-text-ish small/medium/large enum for FUTURE tiered
            // pricing; no real pricing tiers exist yet (business decision,
            // see PHASE_22_4_PARCEL_DESIGN.md) so this is descriptive only
            // today, not read by any pricing calculation.
            $table->string('package_description')->nullable();
            $table->decimal('package_weight_kg', 6, 2)->nullable();
            $table->enum('package_size', ['small', 'medium', 'large'])->default('small');

            $table->enum('status', [
                'pending', 'searching_worker', 'assigned', 'worker_en_route_pickup',
                'picked_up', 'en_route_dropoff', 'delivered', 'cancelled', 'disputed',
            ])->default('pending');

            $table->decimal('price_quoted', 10, 2);
            $table->decimal('price_final', 10, 2)->nullable();
            $table->enum('payment_status', ['pending', 'paid', 'refunded', 'partially_refunded'])->default('pending');
            $table->string('payment_method')->nullable();

            // Two OTPs, not one -- Parcel has two customer-facing handoff
            // events (pickup from sender, delivery to recipient), unlike
            // Service's single provider-arrives-and-works model.
            $table->string('pickup_otp')->nullable();
            $table->string('delivery_otp')->nullable();

            $table->foreignId('cancellation_reason_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cancellation_note')->nullable();
            $table->decimal('cancellation_fee', 10, 2)->nullable();

            $table->string('customer_note')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index(['franchise_id', 'zone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_orders');
    }
};
