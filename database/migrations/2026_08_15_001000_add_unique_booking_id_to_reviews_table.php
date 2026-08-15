<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 (Glover/6amMart parity audit) finding: `reviews` has existed
 * since Phase 1 with a real model, migration, and observer (recomputes
 * providers.rating_avg) — but ZERO Review::create() call sites anywhere
 * in the codebase (confirmed by a full-codebase grep, corroborated
 * independently by the 6amMart parity audit). This migration + the new
 * ReviewService/ReviewController give it a real write path; one review
 * per booking is enforced with a real DB constraint, not just an
 * application-level check, matching the row-locked-uniqueness convention
 * this codebase already uses for Commission/BookingCompensation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->unique('booking_id');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique(['booking_id']);
        });
    }
};
