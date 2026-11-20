<?php

namespace App\Services\Providers;

use App\Models\Booking;
use App\Models\Franchise;
use App\Models\Setting;
use App\Notifications\Channels\PushChannel;
use App\Notifications\ProviderWakeUpNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\DispatchService;
use App\Services\TimezoneResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Owner 2026-11-20: a "book now" job that finds nobody online used to go to no one ("no provider found" after the
 * search window). Now, the first time a search round finds no online provider, approved providers with that skill
 * in that zone who are offline get ONE loud push: "New AC repair job in Kothapet. Open the app and go online to take
 * it." Whoever goes online is offered the job on the next round, exactly like any offer; first to accept gets it.
 *
 * Once per job (never repeated), at most MAX providers (nearest last-known location first), never at night
 * (quiet hours in the franchise's own time zone), and a switch on Admin → Availability & shifts. Push only: an
 * e-mail cannot wake anyone in time. Scheduled bookings are not touched (their offers already reach offline
 * providers). Each alert is written on the booking's history so the admin sees it.
 */
class OfflineWakeUp
{
    public const ENABLED = 'provider.wake_offline.enabled';
    public const QUIET_FROM = 'provider.wake_offline.quiet_from';
    public const QUIET_UNTIL = 'provider.wake_offline.quiet_until';
    public const MAX = 20;

    public function __construct(private DispatchService $dispatch, private TimezoneResolver $timezones)
    {
    }

    public static function enabled(): bool
    {
        return Setting::get(self::ENABLED, '1') === '1';
    }

    public static function quietFrom(): string
    {
        return self::time(Setting::get(self::QUIET_FROM, '22:00'), '22:00');
    }

    public static function quietUntil(): string
    {
        return self::time(Setting::get(self::QUIET_UNTIL, '07:00'), '07:00');
    }

    private static function time(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : $default;
    }

    /** Is it night (quiet hours) right now where this franchise is? Equal times = never quiet. */
    public function isQuiet(?Franchise $franchise, ?Carbon $at = null): bool
    {
        $from = self::quietFrom();
        $until = self::quietUntil();
        if ($from === $until) {
            return false;
        }
        $now = ($at ?? now())->copy()->setTimezone($this->timezones->timezoneFor($franchise))->format('H:i');

        return $from < $until ? ($now >= $from && $now < $until) : ($now >= $from || $now < $until);
    }

    /** @return int how many providers were alerted (0 when off, night, already sent, or nobody to alert) */
    public function send(Booking $booking): int
    {
        $booking->loadMissing(['franchise', 'zone', 'service']);

        if (! self::enabled() || $booking->scheduled_at !== null || $this->isQuiet($booking->franchise)) {
            return 0;
        }

        $channels = array_values(array_intersect(
            ChannelResolver::resolve(array_filter(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id])),
            [PushChannel::class],
        ));
        if ($channels === []) {
            return 0;
        }

        // Once per job, even with several queue workers.
        if (! Cache::add("offline-wake:booking:{$booking->id}", 1, now()->addDay())) {
            return 0;
        }

        $sent = 0;
        foreach ($this->dispatch->findWakeUpCandidates($booking, self::MAX) as $candidate) {
            try {
                $candidate['provider']->user?->notify(new ProviderWakeUpNotification($booking, $channels));
                $sent++;
            } catch (\Throwable $e) {
                Log::error("Offline wake-up failed for provider [{$candidate['provider']->id}], booking [{$booking->id}]: ".$e->getMessage());
            }
        }

        if ($sent > 0) {
            $booking->statusHistory()->create([
                'status' => $booking->status,
                'note' => "Nobody was online: alert sent to {$sent} offline provider(s) to go online and take it.",
                'changed_at' => now(),
            ]);
        }

        return $sent;
    }
}
