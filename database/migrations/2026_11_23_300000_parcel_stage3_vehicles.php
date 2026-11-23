<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parcel Stage 3 (owner go-ahead 2026-11-23): goods vehicles (3-wheeler, mini truck, pickup, truck) with helpers.
 * Additive only, no enum widened: parcel_orders.vehicle_type and field_workers.vehicle_type already exist (Stage 1,
 * string columns); this adds how many helpers the customer asked for, and the number plate a driver gives at sign-up
 * (shown to the customer once the driver is on the way).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcel_orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('helpers')->default(0)->after('vehicle_type');
        });

        Schema::table('field_workers', function (Blueprint $table) {
            $table->string('vehicle_number', 20)->nullable()->after('vehicle_type');
        });
    }

    public function down(): void
    {
        Schema::table('parcel_orders', function (Blueprint $table) {
            $table->dropColumn('helpers');
        });

        Schema::table('field_workers', function (Blueprint $table) {
            $table->dropColumn('vehicle_number');
        });
    }
};
