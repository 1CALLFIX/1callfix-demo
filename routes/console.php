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
