<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// KYC completeness (EOD mission Phase 2/3). Scoped to Provider (Partner)
// specifically -- the mission's own 30-day deadline / withdrawal
// restriction text is written in Partner terms throughout, never
// mentioning Rider/Worker for that specific policy (see
// KNOWN_RISKS_AND_DECISIONS.md for whether the same deadline should apply
// to FieldWorker -- a genuine open question, not assumed here).
//
// kyc_status keeps 'pending' (existing data, existing meaning: submitted,
// not yet reviewed) and ADDS richer states rather than replacing it --
// no lossy data migration. New code paths use the richer states; nothing
// existing that reads/writes 'pending' breaks.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->enum('kyc_status', [
                'pending', 'draft', 'submitted', 'under_review',
                'approved', 'rejected', 'resubmission_required', 'expired',
            ])->default('pending')->change();

            $table->timestamp('kyc_deadline_at')->nullable()->after('kyc_status');
            // none -> reminder -> warning -> final_warning -> overdue. Tracks
            // the last milestone actually notified, so the daily reminder
            // command never re-sends the same milestone twice (idempotent).
            $table->string('kyc_reminder_stage')->nullable()->after('kyc_deadline_at');
            $table->enum('kyc_video_status', ['not_submitted', 'submitted', 'approved', 'rejected'])
                ->default('not_submitted')->after('kyc_reminder_stage');
        });

        // One-time backfill: existing providers that were never given a
        // deadline (created before this feature existed) get one applied
        // retroactively using the SAME resolved 30-day policy new
        // registrations get -- not a different/invented number, and never
        // touches providers already 'approved' (they have nothing to miss).
        // Done in PHP (not a driver-specific DB::raw date function) so it
        // behaves identically on SQLite (tests) and MySQL (production).
        DB::table('providers')
            ->where('kyc_status', '!=', 'approved')
            ->whereNull('kyc_deadline_at')
            ->orderBy('id')
            ->select('id', 'created_at')
            ->get()
            ->each(fn ($row) => DB::table('providers')->where('id', $row->id)->update([
                'kyc_deadline_at' => \Illuminate\Support\Carbon::parse($row->created_at)->addDays(30),
            ]));
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn(['kyc_deadline_at', 'kyc_reminder_stage', 'kyc_video_status']);
            $table->enum('kyc_status', ['pending', 'approved', 'rejected'])->default('pending')->change();
        });
    }
};
