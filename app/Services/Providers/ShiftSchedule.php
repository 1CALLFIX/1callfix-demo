<?php

namespace App\Services\Providers;

use App\Models\Provider;
use App\Models\ProviderShift;
use App\Models\Setting;
use App\Services\TimezoneResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Provider shifts (Swiggy-style slots). The admin defines named windows; a provider picks the ones they will work.
 * Every rule here is an admin setting (Admin → Providers → Availability & shifts):
 *
 *   provider.shifts.mode                    off (default) | reminder | required
 *   provider.shifts.reminder_minutes_before reminder lead time; 0 = none
 *   provider.shifts.grace_minutes           how long after a shift ends a provider may stay online (required mode)
 *   provider.location_stale_after_minutes   silent-heartbeat auto-offline (also used by the dispatcher)
 *
 * With mode `off` nothing changes anywhere. A window's wall-clock times are read in the provider's franchise
 * timezone; a shift whose end is earlier than its start runs past midnight; `days` is the weekday the shift STARTS on.
 */
class ShiftSchedule
{
    public const MODE = 'provider.shifts.mode';
    public const REMINDER_MINUTES = 'provider.shifts.reminder_minutes_before';
    public const GRACE_MINUTES = 'provider.shifts.grace_minutes';
    public const STALE_MINUTES = 'provider.location_stale_after_minutes';

    public const MODES = ['off', 'reminder', 'required'];

    public function __construct(private TimezoneResolver $timezones)
    {
    }

    public static function mode(): string
    {
        $mode = (string) Setting::get(self::MODE, 'off');

        return in_array($mode, self::MODES, true) ? $mode : 'off';
    }

    public static function reminderMinutes(): int
    {
        return max(0, min(240, (int) Setting::get(self::REMINDER_MINUTES, 10)));
    }

    public static function graceMinutes(): int
    {
        return max(0, min(240, (int) Setting::get(self::GRACE_MINUTES, 15)));
    }

    public static function staleMinutes(): int
    {
        // Same floor as the dispatcher's own reading of this setting, so the two can never disagree.
        return max(1, min(1440, (int) Setting::get(self::STALE_MINUTES, 30)));
    }

    public static function isValidTime(string $value): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    /**
     * Concrete [startUtc, endUtc] windows of one slot around $around (yesterday … +$daysAhead, provider-local days).
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    public function windows(ProviderShift $shift, string $timezone, Carbon $around, int $daysAhead = 1): array
    {
        $out = [];
        $localDay = $around->copy()->setTimezone($timezone)->startOfDay();

        for ($offset = -1; $offset <= $daysAhead; $offset++) {
            $day = $localDay->copy()->addDays($offset);

            if (! empty($shift->days) && ! in_array($day->dayOfWeek, array_map('intval', $shift->days), true)) {
                continue;
            }

            $start = $day->copy()->setTimeFromTimeString($shift->start_time);
            $end = $day->copy()->setTimeFromTimeString($shift->end_time);
            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }

            $out[] = [$start->copy()->utc(), $end->copy()->utc()];
        }

        return $out;
    }

    private function timezoneFor(Provider $provider): string
    {
        return $this->timezones->timezoneFor($provider->franchise);
    }

    /** The active slots this provider has chosen. */
    public function chosen(Provider $provider): Collection
    {
        return $provider->shifts()->where('provider_shifts.is_active', true)->get();
    }

    /** Is the provider inside one of their chosen shifts (optionally allowing $graceMinutes after it ends)? */
    public function isWithinShift(Provider $provider, ?Carbon $at = null, int $graceMinutes = 0): bool
    {
        $at = ($at ?? now())->copy()->utc();
        $tz = $this->timezoneFor($provider);

        foreach ($this->chosen($provider) as $shift) {
            foreach ($this->windows($shift, $tz, $at) as [$start, $end]) {
                if ($at->gte($start) && $at->lt($end->copy()->addMinutes($graceMinutes))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** The shift the provider is in right now, with when it ends. */
    public function current(Provider $provider, ?Carbon $at = null): ?array
    {
        $at = ($at ?? now())->copy()->utc();
        $tz = $this->timezoneFor($provider);

        foreach ($this->chosen($provider) as $shift) {
            foreach ($this->windows($shift, $tz, $at) as [$start, $end]) {
                if ($at->gte($start) && $at->lt($end)) {
                    return ['shift' => $shift, 'start' => $start, 'end' => $end];
                }
            }
        }

        return null;
    }

    /** The next chosen shift that starts after $at. */
    public function next(Provider $provider, ?Carbon $at = null): ?array
    {
        $at = ($at ?? now())->copy()->utc();
        $tz = $this->timezoneFor($provider);
        $best = null;

        foreach ($this->chosen($provider) as $shift) {
            foreach ($this->windows($shift, $tz, $at, 7) as [$start, $end]) {
                if ($start->gt($at) && ($best === null || $start->lt($best['start']))) {
                    $best = ['shift' => $shift, 'start' => $start, 'end' => $end];
                }
            }
        }

        return $best;
    }

    /**
     * Chosen-shift windows that START between $from (inclusive) and $to (exclusive), per provider. Used by the
     * reminder command.
     *
     * @return array<int, array{provider: Provider, shift: ProviderShift, start: Carbon}>
     */
    public function startingBetween(Carbon $from, Carbon $to): array
    {
        $hits = [];

        $providers = Provider::query()->whereHas('shifts', fn ($q) => $q->where('provider_shifts.is_active', true))
            ->with(['shifts' => fn ($q) => $q->where('provider_shifts.is_active', true), 'franchise.country', 'user'])
            ->get();

        foreach ($providers as $provider) {
            $tz = $this->timezoneFor($provider);
            foreach ($provider->shifts as $shift) {
                foreach ($this->windows($shift, $tz, $from) as [$start]) {
                    if ($start->gte($from) && $start->lt($to)) {
                        $hits[] = ['provider' => $provider, 'shift' => $shift, 'start' => $start];
                    }
                }
            }
        }

        return $hits;
    }

    /** "08:00" -> "8:00 AM" for display. */
    public static function label(string $hhmm): string
    {
        return Carbon::createFromFormat('H:i', $hhmm)->format('g:i A');
    }
}
