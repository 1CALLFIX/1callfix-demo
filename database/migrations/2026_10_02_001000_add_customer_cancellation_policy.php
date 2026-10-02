<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REF 1CF-CANCEL-POLICY-001 — customer cancellation policy (see docs/CANCELLATION_POLICY_DESIGN.md).
 * One additive migration, no data rewrite:
 *   - bookings: the professional's interim-work declaration made when a job is held for spares, the
 *     expected spare arrival date, dispute state, who cancelled, and which reminders were already sent;
 *   - booking_cancellation_requests: one row per customer cancellation that has to be settled before it
 *     completes (cash / unpaid bookings), with the quoted breakdown for audit;
 *   - provider_reliability_events + providers.reliability_score: a ledger of reliability penalties;
 *   - payments.purpose gains 'cancellation_fee' (a separate gateway payment for the charge).
 */
return new class extends Migration
{
    private const PURPOSES = [
        'booking', 'wallet_topup', 'plan_subscription', 'parcel_order', 'taxi_ride',
        'property_reservation', 'marketplace_order', 'rental_reservation', 'hotel_reservation',
        'booking_bundle',
    ];

    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('cancelled_by_role', 16)->nullable();
            $table->string('spares_sourced_by', 16)->nullable();
            $table->date('spares_expected_at')->nullable();
            $table->unsignedTinyInteger('interim_progress_percent')->nullable();
            $table->decimal('interim_parts_cost', 10, 2)->nullable();
            $table->json('interim_evidence')->nullable();
            $table->timestamp('interim_declared_at')->nullable();
            $table->string('interim_dispute_status', 16)->nullable();
            $table->timestamp('interim_disputed_at')->nullable();
            $table->text('interim_dispute_note')->nullable();
            $table->json('cancellation_fee_basis')->nullable();
            $table->json('spares_notices')->nullable();
        });

        Schema::create('booking_cancellation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            // awaiting_payment | awaiting_admin | completed | waived | superseded
            $table->string('status', 24);
            $table->decimal('total_charge', 10, 2)->default(0);
            $table->decimal('amount_due', 10, 2)->default(0);
            $table->json('basis')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->timestamp('due_by')->nullable();
            $table->timestamp('flagged_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
        });

        Schema::create('provider_reliability_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->smallInteger('points');
            $table->string('note')->nullable();
            $table->timestamps();

            // One penalty per booking per type — a retried sweep can never double-penalise.
            $table->unique(['booking_id', 'type']);
        });

        Schema::table('providers', function (Blueprint $table) {
            $table->unsignedSmallInteger('reliability_score')->default(100);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->enum('purpose', [...self::PURPOSES, 'cancellation_fee'])->default('booking')->change();
        });
    }

    public function down(): void
    {
        // payments.purpose: REFUSE rather than rewrite real payment rows to a different purpose.
        $rows = DB::table('payments')->where('purpose', 'cancellation_fee')->count();
        if ($rows > 0) {
            throw new \RuntimeException(
                "Cannot roll back: {$rows} payment(s) have purpose 'cancellation_fee'. Rolling back would rewrite or lose real "
                .'payment records. Resolve them deliberately first, then roll back.'
            );
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->enum('purpose', self::PURPOSES)->default('booking')->change();
        });

        Schema::table('providers', fn (Blueprint $t) => $t->dropColumn('reliability_score'));
        Schema::dropIfExists('provider_reliability_events');
        Schema::dropIfExists('booking_cancellation_requests');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'cancelled_by_role', 'spares_sourced_by', 'spares_expected_at', 'interim_progress_percent',
                'interim_parts_cost', 'interim_evidence', 'interim_declared_at', 'interim_dispute_status',
                'interim_disputed_at', 'interim_dispute_note', 'cancellation_fee_basis', 'spares_notices',
            ]);
        });
    }
};
