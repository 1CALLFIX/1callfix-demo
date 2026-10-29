<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parcel pickup safety (owner go-ahead 2026-10-29, "drugs and banned items in parcels"). Additive only — no enum
 * is widened, every new column is nullable, so rows written by the old code stay valid:
 *  - parcel_safety_photos: optional photos a rider takes when something looks unusual (the sender, the place,
 *    the item, the packing, the pickup time). Kept on the PRIVATE disk; only admins with parcel_orders.view see them.
 *  - parcel_orders.refused_*: the rider refused the parcel at pickup (reason, note, where, trip pay to the rider).
 *  - parcel_orders.safety_flagged_at: first safety photo or refusal — the admin "Flagged" filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcel_safety_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parcel_order_id')->constrained('parcel_orders')->cascadeOnDelete();
            $table->foreignId('field_worker_id')->nullable()->constrained('field_workers')->nullOnDelete();
            $table->string('stage', 32); // ParcelSettings::SAFETY_PHOTO_STAGES
            $table->json('concerns'); // ParcelSettings::SAFETY_CONCERNS keys
            $table->string('note', 300)->nullable();
            $table->string('disk_path');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->dateTime('taken_at');
            $table->timestamps();

            $table->index(['parcel_order_id', 'taken_at']);
        });

        Schema::table('parcel_orders', function (Blueprint $table) {
            $table->dateTime('refused_at')->nullable()->after('recipient_refused_at');
            $table->string('refusal_reason', 32)->nullable()->after('refused_at');
            $table->string('refusal_note', 300)->nullable()->after('refusal_reason');
            $table->decimal('refused_lat', 10, 7)->nullable()->after('refusal_note');
            $table->decimal('refused_lng', 10, 7)->nullable()->after('refused_lat');
            $table->decimal('refusal_rider_pay', 10, 2)->nullable()->after('refused_lng');
            $table->dateTime('safety_flagged_at')->nullable()->after('refusal_rider_pay');

            $table->index('safety_flagged_at');
        });
    }

    public function down(): void
    {
        Schema::table('parcel_orders', function (Blueprint $table) {
            $table->dropIndex(['safety_flagged_at']);
            $table->dropColumn(['refused_at', 'refusal_reason', 'refusal_note', 'refused_lat', 'refused_lng', 'refusal_rider_pay', 'safety_flagged_at']);
        });

        Schema::dropIfExists('parcel_safety_photos');
    }
};
