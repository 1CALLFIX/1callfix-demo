<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// REF 1CF-IMPLEMENT-20260922-L01 — closes finding L-01 (Phase 2C section D):
// bookings.status carried no record of WHEN a booking entered
// searching_provider, so nothing could ever compute "how overdue is this
// dispatch" without re-deriving it from booking_status_history on every
// read. Two nullable, additive columns:
//
//   dispatch_deadline_at   — despite the name, this is the ANCHOR START
//                             TIME (set once, at the pending ->
//                             searching_provider transition — see
//                             ServiceMatchingJob::handle()), not itself a
//                             fixed deadline moment. DispatchDeadlineSweepService
//                             computes T+5/T+30 by adding the configured
//                             offsets to this timestamp on every sweep run,
//                             the same "the service decides per-row whether
//                             it's due" idiom DailyDigestDispatchService::
//                             sendIfDue() already established, rather than
//                             pre-computing two separate fixed instants here.
//   dispatch_escalated_at  — set once, the first time the T+5 admin alert
//                             fires for this booking — the idempotency
//                             guard that stops the once-a-minute sweep from
//                             re-alerting every run between T+5 and T+30
//                             (or after a manual assignment/cancellation).
//
// Confirmed via full-repo search before writing this: no dispatch-deadline-
// equivalent column exists anywhere in bookings' migration history.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('dispatch_deadline_at')->nullable()->after('status');
            $table->timestamp('dispatch_escalated_at')->nullable()->after('dispatch_deadline_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['dispatch_deadline_at', 'dispatch_escalated_at']);
        });
    }
};
