<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per (campaign, real participant). Polymorphic participant_type/id
// -- same pattern as BadgeAssignment's badgeable_type/id -- because the
// participant's concrete model differs by audience_type (Franchise,
// Provider, FieldWorker, or User-as-customer). A unique constraint on
// (campaign, participant_type, participant_id) is the actual duplicate-
// qualification guard: refreshProgress() always upserts, never inserts a
// second row for the same participant.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_campaign_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_campaign_id')->constrained()->cascadeOnDelete();
            $table->string('participant_type');
            $table->unsignedBigInteger('participant_id');

            $table->decimal('metric_value', 12, 2)->default(0);
            $table->unsignedInteger('rank')->nullable();
            $table->boolean('qualified')->default(false);

            // Fraud-control hook: refreshProgress() may disqualify a
            // participant it previously counted (e.g. a booking it counted
            // was later found to not actually be completed) without
            // deleting the audit row -- the metric_value/rank history stays.
            $table->string('disqualified_reason')->nullable();

            $table->enum('reward_status', ['not_applicable', 'pending', 'approved', 'rejected', 'paid'])->default('pending');
            $table->decimal('reward_amount', 12, 2)->nullable();

            // Idempotency: the exact ref handed to WalletService::credit()/
            // LoyaltyService::earn()'s underlying ledger row, or the
            // BadgeAssignment id for a badge reward. unique() means a
            // second disburse() pass can never double-pay the same
            // participant even under concurrent execution (paired with a
            // lockForUpdate on the campaign row in the service).
            $table->string('reward_ref')->nullable()->unique();

            $table->timestamps();

            $table->unique(['performance_campaign_id', 'participant_type', 'participant_id'], 'perf_campaign_participant_unique');
            $table->index(['performance_campaign_id', 'qualified']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_campaign_participants');
    }
};
