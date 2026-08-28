<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E1 (Multi-Service Booking — Data/Model Foundation). Follows the
 * exact established `payments` pattern every prior vertical used (a plain
 * nullable FK per concrete order type + one new `purpose` enum value):
 * 2026_08_17_005000 (parcel) … 2026_08_22_013000 (hotel).
 *
 * Only `payments` is touched. `commissions` / `reviews` deliberately get no
 * bundle column in E1 — settlement, commission and review behaviour for
 * bundles is out of scope for the data/model foundation and belongs to a
 * later E-step. Child-booking payments, commissions and reviews are
 * completely unaffected and keep using `booking_id`.
 *
 * The `purpose` enum is extended, never reconstructed: every one of the nine
 * existing values is preserved verbatim and `booking_bundle` is appended.
 * `down()` reverts any `booking_bundle` rows to `booking` before shrinking
 * the enum back — the same data-safety step 2026_08_11_037000 introduced and
 * every subsequent enum-shrinking `down()` has repeated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('booking_bundle_id')
                ->nullable()
                ->after('hotel_reservation_id')
                ->constrained('booking_bundles')
                ->nullOnDelete();

            $table->enum('purpose', [
                'booking', 'wallet_topup', 'plan_subscription', 'parcel_order', 'taxi_ride',
                'property_reservation', 'marketplace_order', 'rental_reservation', 'hotel_reservation',
                'booking_bundle',
            ])->default('booking')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            DB::table('payments')->where('purpose', 'booking_bundle')->update(['purpose' => 'booking']);

            $table->enum('purpose', [
                'booking', 'wallet_topup', 'plan_subscription', 'parcel_order', 'taxi_ride',
                'property_reservation', 'marketplace_order', 'rental_reservation', 'hotel_reservation',
            ])->default('booking')->change();

            $table->dropConstrainedForeignId('booking_bundle_id');
        });
    }
};
