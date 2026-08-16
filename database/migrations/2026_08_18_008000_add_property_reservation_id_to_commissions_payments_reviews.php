<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22.7 (Property Rental). Same established pattern as Parcel/Taxi's
 * own additions to these three tables — a plain nullable FK per concrete
 * order type. `reviews` joins that pattern for the first time here
 * (Phase 22.4/22.6 didn't touch it — Parcel/Taxi don't have reviews built
 * yet, only Property does, per real Glover `PropertyReview` evidence).
 * `reviews.booking_id` loosened to nullable the same way `commissions`/
 * `payments`/`dispatch_attempts`' required columns were — every existing
 * row already has a real, non-null `booking_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->foreignId('property_reservation_id')->nullable()->after('taxi_ride_id')->constrained()->nullOnDelete();
            $table->unique('property_reservation_id', 'commissions_property_reservation_id_unique');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('property_reservation_id')->nullable()->after('taxi_ride_id')->constrained()->nullOnDelete();
            $table->enum('purpose', ['booking', 'wallet_topup', 'plan_subscription', 'parcel_order', 'taxi_ride', 'property_reservation'])->default('booking')->change();
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->foreignId('booking_id')->nullable()->change();
            $table->foreignId('property_reservation_id')->nullable()->after('booking_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('property_reservation_id');
            $table->foreignId('booking_id')->nullable(false)->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            DB::table('payments')->where('purpose', 'property_reservation')->update(['purpose' => 'booking']);
            $table->enum('purpose', ['booking', 'wallet_topup', 'plan_subscription', 'parcel_order', 'taxi_ride'])->default('booking')->change();
            $table->dropConstrainedForeignId('property_reservation_id');
        });

        Schema::table('commissions', function (Blueprint $table) {
            $table->dropUnique('commissions_property_reservation_id_unique');
            $table->dropConstrainedForeignId('property_reservation_id');
        });
    }
};
