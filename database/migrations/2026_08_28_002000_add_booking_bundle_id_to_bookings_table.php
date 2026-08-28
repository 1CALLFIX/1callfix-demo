<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E1 (Multi-Service Booking — Data/Model Foundation). Links a child
 * `bookings` row to its wrapping `booking_bundles` row. Purely additive:
 *
 *  - nullable, NO backfill — every existing booking stays
 *    `booking_bundle_id = NULL` and keeps its exact current meaning as a
 *    standalone single-service booking.
 *  - `constrained()` gives the column its own index automatically (same as
 *    every other FK on this table).
 *  - `nullOnDelete` — deleting a bundle detaches its children rather than
 *    cascading; a child booking is a real booking in its own right and must
 *    not disappear with the wrapper.
 *
 * No booking creation / pricing / dispatch / completion behaviour changes
 * here — this migration only adds the column and its FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('booking_bundle_id')
                ->nullable()
                ->after('id')
                ->constrained('booking_bundles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_bundle_id');
        });
    }
};
