<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22.6 (Taxi). Per PHASE_22_5_REMAINING_MODULES_TRIAGE.md: Taxi is
 * the second module to reuse Parcel's proven pattern (FieldWorker capability
 * + dispatch + Setting-driven zero-default rates), not a foundational
 * business-entity decision like Ecommerce/Food/Grocery/Pharmacy. Its own
 * order table, mirroring `parcel_orders`'/`bookings`' proven column shapes.
 * `bookings.service_id` and `parcel_orders` both remain untouched.
 *
 * Ships fully inert: `modules.is_implemented` for `taxi` stays `false`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxi_rides', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();

            $table->foreignId('pickup_address_id')->constrained('addresses')->cascadeOnDelete();
            $table->foreignId('dropoff_address_id')->nullable()->constrained('addresses')->nullOnDelete();

            // The driver who accepted this ride -- a FieldWorker holding
            // the pre-existing 'taxi_driver' capability (App\Support\
            // WorkerTypes, already seeded, not a new capability type),
            // same reuse Parcel established for 'parcel_rider'.
            $table->foreignId('assigned_worker_id')->nullable()->constrained('field_workers')->nullOnDelete();

            $table->enum('status', [
                'requested', 'searching_driver', 'assigned', 'driver_en_route',
                'trip_started', 'trip_completed', 'cancelled', 'disputed',
            ])->default('requested');

            // Fare components stored per-ride, not just the total -- so a
            // real fare model (once decided) has somewhere to record HOW a
            // price was reached, not just what it was. distance_km is
            // recorded at trip completion (Haversine between pickup/dropoff,
            // same primitive DispatchService::haversineKm() already provides).
            $table->decimal('distance_km', 6, 2)->nullable();
            $table->decimal('price_quoted', 10, 2);
            $table->decimal('price_final', 10, 2)->nullable();
            $table->enum('payment_status', ['pending', 'paid', 'refunded', 'partially_refunded'])->default('pending');
            $table->string('payment_method')->nullable();

            // One OTP -- verifies the driver has the correct passenger at
            // pickup, mirroring bookings.start_otp's exact role. No second
            // OTP at drop-off (unlike Parcel's two-handoff model) -- a taxi
            // trip has one real verification point, not two.
            $table->string('start_otp')->nullable();

            $table->foreignId('cancellation_reason_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cancellation_note')->nullable();
            $table->decimal('cancellation_fee', 10, 2)->nullable();

            $table->string('customer_note')->nullable();
            $table->timestamp('trip_started_at')->nullable();
            $table->timestamp('trip_completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index(['franchise_id', 'zone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxi_rides');
    }
};
