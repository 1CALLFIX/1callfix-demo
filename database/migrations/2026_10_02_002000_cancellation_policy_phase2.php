<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REF 1CF-CANCEL-POLICY-001 phase 2 — additive only:
 *   - bookings: policy snapshot taken at booking time + the professional's verified arrival (GPS + time);
 *   - booking_quotes: the in-app quote a professional must send before a quote-rejected cancel;
 *   - booking_call_attempts: in-app call log backing the "customer unreachable" cancel;
 *   - plans: per-plan toggle for whether visit-charge waivers cover the en-route and visit charges.
 * Setting-change auditing reuses the existing activity_log via SettingsAuditor (no new table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->json('cancellation_policy_snapshot')->nullable();
            $table->decimal('arrival_lat', 10, 7)->nullable();
            $table->decimal('arrival_lng', 10, 7)->nullable();
            $table->timestamp('arrival_verified_at')->nullable();
            $table->unsignedInteger('arrival_distance_m')->nullable();
        });

        Schema::create('booking_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('status', 16)->default('sent'); // sent | accepted | rejected | expired
            $table->timestamp('sent_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
        });

        Schema::create('booking_call_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->timestamp('attempted_at');
            $table->timestamps();

            $table->index('booking_id');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('waives_cancellation_visit_charges')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('waives_cancellation_visit_charges'));
        Schema::dropIfExists('booking_call_attempts');
        Schema::dropIfExists('booking_quotes');
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn([
            'cancellation_policy_snapshot', 'arrival_lat', 'arrival_lng', 'arrival_verified_at', 'arrival_distance_m',
        ]));
    }
};
