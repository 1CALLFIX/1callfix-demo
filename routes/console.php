<?php

use App\Support\ScheduleRunTracker;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Every entry below is wrapped with ScheduleRunTracker's before()/
 * onSuccess()/onFailure() hooks (Operations expansion, mission Phase 10)
 * so /admin/operations can show REAL last-run status for each scheduled
 * task, not a guess. See ScheduleRunTracker::track()'s own docblock.
 * (A top-level `function` declaration here would fatal with "cannot
 * redeclare" the second time this file loads within one process --
 * ScheduleRunTracker::track() is a static method precisely to avoid that.)
 */

// Notification Center: dispatches scheduled campaigns/meeting reminders
// once their scheduled_at arrives. Requires the server's real cron to be
// pointed at `php artisan schedule:run` every minute (standard Laravel
// deployment step) -- see the deployment report for whether that's
// configured on this box yet.
ScheduleRunTracker::track(Schedule::command('campaigns:dispatch-due'), 'campaigns:dispatch-due')->everyMinute();

// Plan Engine: closes due subscription periods, applies rollover, applies
// queued upgrades/downgrades, and drives active -> past_due/grace_period ->
// expired. Hourly is enough granularity for billing-period boundaries
// (unlike campaign dispatch, which needs minute precision for scheduled
// sends) -- see RenewalService. Same schedule:run cron caveat as above.
ScheduleRunTracker::track(Schedule::command('plans:renew-due'), 'plans:renew-due')->hourly();

// Referral Engine: expires pending referrals past their opt-in
// referral.pending_expiry_days window (housekeeping only -- see
// ReferralService::expirePendingReferrals()'s own docblock for why this
// can never race a legitimate reward). Daily is enough granularity for a
// day-scale expiry window; same schedule:run cron caveat as above.
ScheduleRunTracker::track(Schedule::command('referrals:expire-due'), 'referrals:expire-due')->daily();
ScheduleRunTracker::track(Schedule::command('kyc:send-reminders'), 'kyc:send-reminders')->daily();
