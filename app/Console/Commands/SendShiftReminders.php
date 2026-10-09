<?php

namespace App\Console\Commands;

use App\Notifications\ProviderShiftReminderNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\Providers\ShiftSchedule;
use App\Services\TimezoneResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs every minute. Reminds a provider, `provider.shifts.reminder_minutes_before` minutes ahead, that a shift they
 * chose is about to start — only when the admin has shifts on (mode reminder or required) and only if the provider is
 * not already online. One reminder per provider per shift window.
 */
class SendShiftReminders extends Command
{
    protected $signature = 'providers:shift-reminders';

    protected $description = 'Remind providers that a chosen shift is about to start';

    public function handle(ShiftSchedule $schedule, TimezoneResolver $timezones): int
    {
        $minutes = ShiftSchedule::reminderMinutes();

        if (ShiftSchedule::mode() === 'off' || $minutes === 0) {
            return self::SUCCESS;
        }

        $from = now()->addMinutes($minutes)->startOfMinute();
        $sent = 0;

        foreach ($schedule->startingBetween($from, $from->copy()->addMinute()) as $hit) {
            $provider = $hit['provider'];

            if ($provider->is_online || ! $provider->user) {
                continue;
            }

            if (! Cache::add("shift-reminder:{$provider->id}:{$hit['shift']->id}:{$hit['start']->timestamp}", 1, now()->addHours(3))) {
                continue;
            }

            try {
                $provider->user->notify(new ProviderShiftReminderNotification(
                    $hit['shift']->name,
                    (string) $timezones->format($hit['start'], $provider->franchise, 'g:i A'),
                    $minutes,
                    ChannelResolver::resolve(array_filter(['zone_id' => $provider->zone_id, 'franchise_id' => $provider->franchise_id])),
                ));
                $sent++;
            } catch (\Throwable $e) {
                Log::error("Shift reminder failed for provider [{$provider->id}]: ".$e->getMessage());
            }
        }

        $this->info("Sent {$sent} shift reminder(s).");

        return self::SUCCESS;
    }
}
