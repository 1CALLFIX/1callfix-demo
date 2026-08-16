<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22.6 (Taxi). Same pattern Phase 22.4 established for Parcel: a
 * plain nullable FK per concrete order type, not a jump to full
 * polymorphism now that there are three (Booking/ParcelOrder/TaxiRide) --
 * matching `payments.plan_subscription_id`'s own precedent, which this
 * codebase has now applied consistently three times running. `booking_id`/
 * `parcel_order_id` are already nullable from Phase 22.4; every existing
 * row already has correct values for whichever column actually applies to
 * it, so this remains purely additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->foreignId('taxi_ride_id')->nullable()->after('parcel_order_id')->constrained()->nullOnDelete();
            $table->unique('taxi_ride_id', 'commissions_taxi_ride_id_unique');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('taxi_ride_id')->nullable()->after('parcel_order_id')->constrained()->nullOnDelete();
            $table->enum('purpose', ['booking', 'wallet_topup', 'plan_subscription', 'parcel_order', 'taxi_ride'])->default('booking')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            DB::table('payments')->where('purpose', 'taxi_ride')->update(['purpose' => 'booking']);
            $table->enum('purpose', ['booking', 'wallet_topup', 'plan_subscription', 'parcel_order'])->default('booking')->change();
            $table->dropConstrainedForeignId('taxi_ride_id');
        });

        Schema::table('commissions', function (Blueprint $table) {
            $table->dropUnique('commissions_taxi_ride_id_unique');
            $table->dropConstrainedForeignId('taxi_ride_id');
        });
    }
};
