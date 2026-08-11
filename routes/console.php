<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Notification Center: dispatches scheduled campaigns/meeting reminders
// once their scheduled_at arrives. Requires the server's real cron to be
// pointed at `php artisan schedule:run` every minute (standard Laravel
// deployment step) -- see the deployment report for whether that's
// configured on this box yet.
Schedule::command('campaigns:dispatch-due')->everyMinute();

// Plan Engine: closes due subscription periods, applies rollover, applies
// queued upgrades/downgrades, and drives active -> past_due/grace_period ->
// expired. Hourly is enough granularity for billing-period boundaries
// (unlike campaign dispatch, which needs minute precision for scheduled
// sends) -- see RenewalService. Same schedule:run cron caveat as above.
Schedule::command('plans:renew-due')->hourly();
