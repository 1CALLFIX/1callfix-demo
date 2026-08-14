<?php

namespace App\Support;

use App\Models\ScheduledTaskRun;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Carbon;

/**
 * Real last-run tracking for Schedule::command() entries (Operations
 * expansion, mission Phase 10, item 3 -- Scheduler/CRON). Laravel's own
 * scheduler has no persisted run history by default; this records what
 * ACTUALLY happened via before()/onSuccess()/onFailure() hooks wired onto
 * every entry in routes/console.php, never a fabricated "healthy" status.
 * No row for a command simply means it hasn't run since this existed --
 * the Operations screen says exactly that.
 */
class ScheduleRunTracker
{
    /** @var array<string, Carbon> */
    private static array $starts = [];

    /** Attaches the before()/onSuccess()/onFailure() hooks to a Schedule::command() Event and returns it, so callers can keep chaining ->everyMinute() etc. */
    public static function track(Event $event, string $command): Event
    {
        return $event
            ->before(fn () => self::starting($command))
            ->onSuccess(fn () => self::succeeded($command))
            ->onFailure(fn () => self::failed($command));
    }

    public static function starting(string $command): void
    {
        self::$starts[$command] = now();
    }

    public static function succeeded(string $command): void
    {
        self::record($command, 'success');
    }

    public static function failed(string $command, string $output = ''): void
    {
        self::record($command, 'failure', $output);
    }

    private static function record(string $command, string $status, string $output = ''): void
    {
        $start = self::$starts[$command] ?? now();

        ScheduledTaskRun::create([
            'command' => $command,
            'status' => $status,
            'output' => $output ?: null,
            'started_at' => $start,
            'finished_at' => now(),
        ]);

        unset(self::$starts[$command]);
    }
}
