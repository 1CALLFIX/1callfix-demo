<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RENTAL MODULE IMPLEMENTATION -- same established pattern as every prior
 * vertical's own addition to these three tables (a plain nullable FK per
 * concrete order type), this time for the shared Vehicle/Equipment
 * `rental_reservations` engine. Property Rental's own
 * `property_reservation_id` columns (Phase 22.7) are untouched -- Property
 * keeps using its own existing FK/purpose value, only Vehicle/Equipment
 * reservations use this new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->foreignId('rental_reservation_id')->nullable()->after('marketplace_order_id')->constrained()->nullOnDelete();
            $table->unique('rental_reservation_id', 'commissions_rental_reservation_id_unique');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('rental_reservation_id')->nullable()->after('marketplace_order_id')->constrained()->nullOnDelete();
            $table->enum('purpose', ['booking', 'wallet_topup', 'plan_subscription', 'parcel_order', 'taxi_ride', 'property_reservation', 'marketplace_order', 'rental_reservation'])->default('booking')->change();
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->foreignId('rental_reservation_id')->nullable()->after('marketplace_order_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rental_reservation_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            DB::table('payments')->where('purpose', 'rental_reservation')->update(['purpose' => 'booking']);
            $table->enum('purpose', ['booking', 'wallet_topup', 'plan_subscription', 'parcel_order', 'taxi_ride', 'property_reservation', 'marketplace_order'])->default('booking')->change();
            $table->dropConstrainedForeignId('rental_reservation_id');
        });

        Schema::table('commissions', function (Blueprint $table) {
            $table->dropUnique('commissions_rental_reservation_id_unique');
            $table->dropConstrainedForeignId('rental_reservation_id');
        });
    }
};
