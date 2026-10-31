<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parcel Stage 2 — routes (owner decisions 2026-10-31). Additive only — no enum is widened, every new column is
 * nullable or has a default, so rows written by the old code stay valid:
 *  - Multi-drop: one pickup, more drop points. The order's own dropoff_address_id / recipient_* / delivery_otp stay
 *    the FIRST drop; parcel_order_stops holds drop 2, 3, ... each with its own recipient and delivery code.
 *    drop_delivered_at = the first drop was handed over (the order's delivered_at still means "finished").
 *  - Return trip: the rider brings something back to the sender. return_otp is the sender's code for that hand-back;
 *    drops_completed_at = every drop done (the return leg starts), returned_at = handed back to the sender.
 *    While returning, status stays en_route_dropoff (no enum change).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcel_orders', function (Blueprint $table) {
            $table->boolean('return_trip')->default(false)->after('vehicle_type');
            $table->string('return_otp')->nullable()->after('delivery_otp');
            $table->dateTime('drop_delivered_at')->nullable()->after('picked_up_at');
            $table->dateTime('drops_completed_at')->nullable()->after('drop_delivered_at');
            $table->dateTime('returned_at')->nullable()->after('drops_completed_at');
        });

        Schema::create('parcel_order_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parcel_order_id')->constrained('parcel_orders')->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence'); // 2, 3, ... (drop 1 is the order's own dropoff)
            $table->foreignId('address_id')->constrained('addresses');
            $table->string('recipient_name', 120)->nullable();
            $table->string('recipient_phone', 20);
            $table->string('delivery_otp')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['parcel_order_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_order_stops');

        Schema::table('parcel_orders', function (Blueprint $table) {
            $table->dropColumn(['return_trip', 'return_otp', 'drop_delivered_at', 'drops_completed_at', 'returned_at']);
        });
    }
};
