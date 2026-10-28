<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parcel Stage 1 (owner go-ahead 2026-10-25, after the Borzo / Shiprocket study). Additive only — no enum is
 * widened, every new column is nullable or has a default, so rows written by the old code stay valid:
 *  - Now | Schedule: delivery_type + scheduled_at (pickup time, UTC); dispatch_released_at marks the one moment
 *    a scheduled order was handed to riders (parcel:release-scheduled), so it is never released twice.
 *  - Contents + restricted items: package_contents (a ParcelSettings::CONTENTS key) and the time the sender
 *    confirmed the parcel holds nothing on the restricted list.
 *  - Vehicle: the vehicle an order needs and the vehicle a rider drives (two-wheeler only for now).
 *  - Waiting charge at pickup: when the rider arrived, the billed minutes and the amount (paid to the rider).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcel_orders', function (Blueprint $table) {
            $table->string('delivery_type', 16)->default('now')->after('package_size');
            $table->dateTime('scheduled_at')->nullable()->after('delivery_type');
            $table->dateTime('dispatch_released_at')->nullable()->after('scheduled_at');
            $table->string('package_contents', 32)->nullable()->after('package_description');
            $table->dateTime('restricted_items_confirmed_at')->nullable()->after('package_contents');
            $table->string('vehicle_type', 32)->default('two_wheeler')->after('dispatch_released_at');
            $table->dateTime('arrived_at_pickup_at')->nullable()->after('picked_up_at');
            $table->unsignedSmallInteger('waiting_minutes')->nullable()->after('arrived_at_pickup_at');
            $table->decimal('waiting_charge', 10, 2)->nullable()->after('waiting_minutes');

            $table->index(['status', 'delivery_type', 'scheduled_at'], 'parcel_orders_schedule_idx');
        });

        Schema::table('field_workers', function (Blueprint $table) {
            $table->string('vehicle_type', 32)->default('two_wheeler')->after('zone_id');
        });
    }

    public function down(): void
    {
        Schema::table('parcel_orders', function (Blueprint $table) {
            $table->dropIndex('parcel_orders_schedule_idx');
            $table->dropColumn([
                'delivery_type', 'scheduled_at', 'dispatch_released_at', 'package_contents',
                'restricted_items_confirmed_at', 'vehicle_type', 'arrived_at_pickup_at', 'waiting_minutes', 'waiting_charge',
            ]);
        });

        Schema::table('field_workers', function (Blueprint $table) {
            $table->dropColumn('vehicle_type');
        });
    }
};
