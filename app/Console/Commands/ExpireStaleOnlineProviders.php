<?php

namespace App\Console\Commands;

use App\Models\Provider;
use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * A provider who closes the tab, loses signal or backgrounds the app stops
 * sending the 2-minute location heartbeat but stays `is_online = true`
 * forever. Dispatch already skips them once their fix goes stale (same
 * `provider.location_stale_after_minutes` window), so no offer is wasted —
 * this just makes the flag truthful so admin lists and the provider's own
 * header stop claiming they are available. Offline is written directly (no
 * coordinates touched); the provider simply taps "Go online" to return.
 */
class ExpireStaleOnlineProviders extends Command
{
    protected $signature = 'providers:expire-stale-online';

    protected $description = 'Sets providers offline whose location heartbeat has been silent past provider.location_stale_after_minutes';

    public function handle(): int
    {
        $cutoff = now()->subMinutes(\App\Services\Providers\ShiftSchedule::staleMinutes());

        $count = Provider::query()
            ->where('is_online', true)
            ->where(function ($q) use ($cutoff) {
                $q->where('location_updated_at', '<', $cutoff)
                    ->orWhere(function ($q) use ($cutoff) {
                        $q->whereNull('location_updated_at')->where('updated_at', '<', $cutoff);
                    });
            })
            ->update(['is_online' => false]);

        // Shifts required: anyone still online outside every chosen shift (past the admin's grace) goes offline too.
        $outOfShift = 0;
        if (\App\Services\Providers\ShiftSchedule::mode() === 'required') {
            $schedule = app(\App\Services\Providers\ShiftSchedule::class);
            $grace = \App\Services\Providers\ShiftSchedule::graceMinutes();

            Provider::query()->where('is_online', true)->with('franchise.country')->get()
                ->each(function (Provider $provider) use ($schedule, $grace, &$outOfShift) {
                    if (! $schedule->isWithinShift($provider, now(), $grace)) {
                        $provider->forceFill(['is_online' => false])->save();
                        $outOfShift++;
                    }
                });
        }

        $this->info("Set {$count} stale provider(s) offline" . ($outOfShift ? " and {$outOfShift} outside their shift." : '.'));

        return self::SUCCESS;
    }
}
