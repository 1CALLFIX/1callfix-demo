<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22.4 (Parcel) — DispatchService generalization, decided by building
 * Parcel. Phase B0.3 already added `notifiable_type`/`notifiable_id`
 * (WORKER side — Provider vs FieldWorker) to `dispatch_attempts`; that
 * migration's own docblock explicitly anticipated this exact moment
 * ("without a second schema change at that point"). This is the ORDER-side
 * counterpart, using the identical polymorphic pattern (consistent with
 * the table's own established precedent, unlike commissions/payments,
 * where a plain nullable FK was already this codebase's precedent for a
 * two-concrete-type relationship — each table follows its OWN prior art).
 *
 * `booking_id` AND `provider_id` are both loosened to nullable so a Parcel
 * dispatch offer (which has no booking, and notifies a FieldWorker via the
 * existing `notifiable_type/id` columns, not a Provider) can exist —
 * every existing row already has real, non-null values for both, so this
 * is purely additive. `DispatchService::findCandidates()` (Service's
 * existing method) is completely untouched and continues to write
 * `booking_id`+`provider_id` rows exactly as before; only the NEW
 * `findWorkerCandidates()` path (Parcel) writes `dispatchable_type/id`
 * (order side) + `notifiable_type/id` (worker side) instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_attempts', function (Blueprint $table) {
            $table->foreignId('booking_id')->nullable()->change();
            $table->foreignId('provider_id')->nullable()->change();
            $table->string('dispatchable_type')->nullable()->after('notifiable_id');
            $table->unsignedBigInteger('dispatchable_id')->nullable()->after('dispatchable_type');
            $table->index(['dispatchable_type', 'dispatchable_id'], 'dispatch_attempts_dispatchable_idx');
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_attempts', function (Blueprint $table) {
            $table->dropIndex('dispatch_attempts_dispatchable_idx');
            $table->dropColumn(['dispatchable_type', 'dispatchable_id']);
            $table->foreignId('provider_id')->nullable(false)->change();
            $table->foreignId('booking_id')->nullable(false)->change();
        });
    }
};
