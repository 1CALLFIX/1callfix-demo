<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E1 (Multi-Service Booking — Data/Model Foundation). An additive
 * wrapper table over one-or-more child `bookings` rows. It is NOT a generic
 * replacement orders table (that was considered and rejected — the same
 * conclusion PHASE_22_2_ORDER_ENGINE_ARCHITECTURE_DECISION.md reached, now
 * re-confirmed by Phase E discovery); every existing single-service booking
 * is left completely untouched with `booking_bundle_id = NULL`.
 *
 * `status` is a plain stored latch (active/completed/cancelled) exactly as
 * the discovery mandated — the live cross-child picture is derived on demand
 * from the child bookings (BookingBundle::derivedStatus()), never written
 * back here. No bundle lifecycle / dispatch / pricing / payment behaviour is
 * introduced in E1; those are E2–E7.
 *
 * Column shapes mirror `bookings` (the thing a bundle wraps) and
 * `marketplace_orders` (the most recent order-shaped table): decimal(10,2)
 * money, franchise cascadeOnDelete, nullable zone nullOnDelete, softDeletes,
 * the same payment_status vocabulary. `payment_method` is nullable here
 * (unlike `bookings`, which defaults to 'online') because a bundle has no
 * payment method until a real checkout flow chooses one in a later step —
 * matching `marketplace_orders.payment_method`.
 *
 * `booking_bundle_sequences` is created here too — the same dedicated
 * per-franchise-per-day atomic counter every other vertical already has
 * (booking_sequences, parcel_order_sequences, marketplace_order_sequences,
 * …). A bundle is its own order stream, so it draws from its own pool via
 * OrderCodeService::generateForBookingBundle(), never Service's counter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_bundles', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('address_id')->constrained('addresses')->cascadeOnDelete();

            $table->enum('status', ['active', 'completed', 'cancelled'])->default('active');

            $table->decimal('total_price_quoted', 10, 2);
            $table->decimal('total_price_final', 10, 2)->nullable(); // set at settlement in a later step, same shape as bookings.price_final

            $table->enum('payment_status', ['pending', 'paid', 'refunded', 'partially_refunded'])->default('pending');
            $table->enum('payment_method', ['online', 'cash', 'wallet'])->nullable();

            $table->text('cancellation_note')->nullable();
            $table->decimal('cancellation_fee', 10, 2)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status', 'booking_bundles_status_index');
            $table->index(['franchise_id', 'zone_id'], 'booking_bundles_franchise_zone_index');
            $table->index('customer_id', 'booking_bundles_customer_id_index');
        });

        Schema::create('booking_bundle_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->date('sequence_date');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['franchise_id', 'sequence_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_bundle_sequences');
        Schema::dropIfExists('booking_bundles');
    }
};
