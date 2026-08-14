<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Same additive enum widening as providers (migration 016000) -- 'pending'
// keeps its existing meaning, richer states are added. Deliberately NO
// kyc_deadline_at/withdrawal-restriction columns here -- the mission's own
// 30-day deadline text is Partner-specific throughout; whether the same
// policy applies to Riders/Workers is a real open question, logged in
// KNOWN_RISKS_AND_DECISIONS.md rather than assumed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_workers', function (Blueprint $table) {
            $table->enum('kyc_status', [
                'pending', 'draft', 'submitted', 'under_review',
                'approved', 'rejected', 'resubmission_required', 'expired',
            ])->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('field_workers', function (Blueprint $table) {
            $table->enum('kyc_status', ['pending', 'approved', 'rejected'])->default('pending')->change();
        });
    }
};
