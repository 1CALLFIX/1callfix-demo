<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REF 1CF-SCHEDULING-DISPATCH-001 — the scheduled-booking counterpart to
 * dispatch_deadline_at/dispatch_escalated_at (added for ASAP dispatch by
 * 2026_09_22_001000_add_dispatch_deadline_to_bookings_table.php). Kept as
 * its OWN set of columns rather than reusing those two: the ASAP sweep
 * (DispatchDeadlineSweepService) explicitly excludes every scheduled
 * booking (`whereNull('scheduled_at')`) and its own T+5/T+30 semantics
 * don't apply here at all — a scheduled booking's timeline is anchored to
 * `scheduled_at`, not to when dispatch started.
 *
 *   scheduled_offers_sent_at    — when the open-offer cycle first released
 *                                 (payment-gate cleared). Null until then;
 *                                 doubles as the idempotency guard so the
 *                                 scheduler catch-up command never
 *                                 re-releases a booking twice.
 *   scheduled_last_offer_at     — last time ANY offer (initial send or a
 *                                 re-offer/reminder push) went out. Drives
 *                                 the admin-configurable re-offer interval
 *                                 (default 2h).
 *   scheduled_early_warning_at  — Part "B" early-warning milestone
 *                                 (scheduled_at - early_warning_hours,
 *                                 default 3h): admin alert + customer
 *                                 "still finding your professional" notice.
 *   scheduled_urgent_alert_at   — urgent admin-only alert at
 *                                 scheduled_at - buffer (the same unified
 *                                 customer-scheduling buffer from Part 1).
 *   scheduled_reminder_1_at /
 *   scheduled_reminder_2_at     — provider reminders before scheduled_at
 *                                 (defaults T-60/T-30, both admin
 *                                 configurable). Stamped even when a
 *                                 milestone is deliberately SKIPPED because
 *                                 the provider was assigned after that
 *                                 milestone's time had already passed (see
 *                                 ScheduledBookingReminderService) — this
 *                                 column means "this milestone is settled",
 *                                 not strictly "a reminder was sent".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('scheduled_offers_sent_at')->nullable()->after('dispatch_escalated_at');
            $table->timestamp('scheduled_last_offer_at')->nullable()->after('scheduled_offers_sent_at');
            $table->timestamp('scheduled_early_warning_at')->nullable()->after('scheduled_last_offer_at');
            $table->timestamp('scheduled_urgent_alert_at')->nullable()->after('scheduled_early_warning_at');
            $table->timestamp('scheduled_reminder_1_at')->nullable()->after('scheduled_urgent_alert_at');
            $table->timestamp('scheduled_reminder_2_at')->nullable()->after('scheduled_reminder_1_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'scheduled_offers_sent_at',
                'scheduled_last_offer_at',
                'scheduled_early_warning_at',
                'scheduled_urgent_alert_at',
                'scheduled_reminder_1_at',
                'scheduled_reminder_2_at',
            ]);
        });
    }
};
