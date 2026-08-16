<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22.7 (Property Rental) — the critical concurrency-safety table.
 * Schema verified directly against Glover's real `property_availabilities`
 * migration, including its own `unique(['property_id', 'date'])`
 * constraint — kept exactly, because that real DB-level constraint IS the
 * genuine double-booking backstop this phase's own brief demanded
 * ("do not pretend a simple application-level check-then-insert is
 * concurrency-safe"). One row per property per calendar day; absence of a
 * row means "available" (the sparse-default Glover itself uses, not
 * reinvented here) — `PropertyReservationService::reserve()` combines a
 * `lockForUpdate()` read of any EXISTING rows in the requested range
 * (serializing concurrent access when a row already exists) with this
 * UNIQUE constraint (the real backstop for the case where NEITHER
 * concurrent transaction sees a pre-existing row — the database itself
 * rejects the second INSERT).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->boolean('is_available')->default(true);
            $table->decimal('price_override', 10, 2)->nullable();
            $table->enum('reason', ['available', 'booked', 'blocked', 'maintenance'])->default('available');
            $table->foreignId('property_reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['property_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_availabilities');
    }
};
