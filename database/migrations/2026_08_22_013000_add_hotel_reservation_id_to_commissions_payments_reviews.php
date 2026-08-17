<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HOTEL / STAY BOOKING MODULE -- same established pattern as every prior
 * vertical's own addition to these three tables (a plain nullable FK per
 * concrete order type). Every existing `*_id` column on these three tables
 * (parcel_order/taxi_ride/property_reservation/marketplace_order/
 * rental_reservation) is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->foreignId('hotel_reservation_id')->nullable()->after('rental_reservation_id')->constrained()->nullOnDelete();
            $table->unique('hotel_reservation_id', 'commissions_hotel_reservation_id_unique');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('hotel_reservation_id')->nullable()->after('rental_reservation_id')->constrained()->nullOnDelete();
            $table->enum('purpose', ['booking', 'wallet_topup', 'plan_subscription', 'parcel_order', 'taxi_ride', 'property_reservation', 'marketplace_order', 'rental_reservation', 'hotel_reservation'])->default('booking')->change();
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->foreignId('hotel_reservation_id')->nullable()->after('rental_reservation_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hotel_reservation_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            DB::table('payments')->where('purpose', 'hotel_reservation')->update(['purpose' => 'booking']);
            $table->enum('purpose', ['booking', 'wallet_topup', 'plan_subscription', 'parcel_order', 'taxi_ride', 'property_reservation', 'marketplace_order', 'rental_reservation'])->default('booking')->change();
            $table->dropConstrainedForeignId('hotel_reservation_id');
        });

        Schema::table('commissions', function (Blueprint $table) {
            $table->dropUnique('commissions_hotel_reservation_id_unique');
            $table->dropConstrainedForeignId('hotel_reservation_id');
        });
    }
};
