<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HOTEL / STAY BOOKING MODULE. A room type can have multiple rate plans
 * (Standard Rate / Breakfast Included / Half Board / Full Board /
 * Non-Refundable / Flexible Cancellation, per the mission brief's own
 * examples) -- each its own nightly price. `nightly_rate` is the real,
 * required, admin-set commercial number (no invented default beyond `0`,
 * which is never a usable price and forces a deliberate admin entry,
 * matching this codebase's own established "every rate starts at a
 * safe/inert default" convention -- see KNOWN_RISKS_AND_DECISIONS.md item
 * 5's compensation-rate precedent).
 *
 * `meal_plan` and `cancellation_policy_label` are DESCRIPTIVE/display
 * metadata only -- what the rate plan is called and what it includes, not
 * an enforcement mechanism. BUSINESS DECISION REQUIRED, documented rather
 * than invented: whether `cancellation_policy_label = 'non_refundable'`
 * should actually force a 100% cancellation fee (vs. today's behavior,
 * where every rate plan's real refund amount is computed identically by
 * `CancellationService::calculateFeeForHotelReservation()`'s existing
 * Setting-driven free-window/fee-type/fee-value mechanism, regardless of
 * this label). Wiring the label into an enforced fee override is a real,
 * scoped follow-up once that decision is made -- not fabricated here.
 *
 * No `cancellation_policy_id` FK to the unused `cancellation_policies`
 * table -- same established precedent `properties`/`hotel_room_types`
 * already follow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_rate_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_room_type_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->enum('meal_plan', ['room_only', 'breakfast', 'half_board', 'full_board'])->default('room_only');
            $table->enum('cancellation_policy_label', ['flexible', 'non_refundable'])->default('flexible');

            $table->decimal('nightly_rate', 10, 2)->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['hotel_room_type_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_rate_plans');
    }
};
