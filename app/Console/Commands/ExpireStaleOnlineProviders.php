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
        $cutoff = now()->subMinutes(max(1, (int) Setting::get('provider.location_stale_after_minutes', '30')));

        $count = Provider::query()
            ->where('is_online', true)
            ->where(function ($q) use ($cutoff) {
                $q->where('location_updated_at', '<', $cutoff)
                    ->orWhere(function ($q) use ($cutoff) {
                        $q->whereNull('location_updated_at')->where('updated_at', '<', $cutoff);
                    });
            })
            ->update(['is_online' => false]);

        $this->info("Set {$count} stale provider(s) offline.");

        return self::SUCCESS;
    }
}
