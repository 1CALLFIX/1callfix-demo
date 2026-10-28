<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Turnaround time (TAT) for any admin list: how long a booking, order, payment, refund or request took from the
 * moment it was created to the moment it closed. Display only — analytics read the same two timestamps.
 */
class Turnaround
{
    /** Statuses that end an order, booking, ride or reservation (status-history tables share this vocabulary). */
    public const CLOSED_STATUSES = ['completed', 'delivered', 'cancelled', 'rejected', 'failed', 'refunded', 'returned', 'checked_out', 'expired', 'recipient_refused'];

    /**
     * When and how a status-driven record closed, for the Completed / TAT columns.
     * The success time comes from its own column ($doneAttribute, e.g. completed_at / delivered_at); any other
     * close (cancelled, refused, ...) comes from the status-history row for that status — eager-load it with
     * Turnaround::historyScope() — and falls back to updated_at.
     *
     * @return array{at: ?CarbonInterface, outcome: ?string, open: bool}
     */
    public static function close(Model $record, ?string $doneAttribute = null): array
    {
        $done = $doneAttribute ? $record->{$doneAttribute} : null;
        if ($done) {
            return ['at' => Carbon::parse($done), 'outcome' => in_array($record->status, self::CLOSED_STATUSES, true) ? $record->status : 'completed', 'open' => false];
        }
        if (! in_array($record->status, self::CLOSED_STATUSES, true)) {
            return ['at' => null, 'outcome' => null, 'open' => true];
        }

        $at = $record->relationLoaded('statusHistory')
            ? $record->statusHistory->where('status', $record->status)->sortByDesc('changed_at')->first()?->changed_at
            : null;

        return ['at' => $at ?? $record->updated_at, 'outcome' => $record->status, 'open' => false];
    }

    /** with(['statusHistory' => Turnaround::historyScope()]) — only the closing rows, so a list page stays light. */
    public static function historyScope(): \Closure
    {
        return fn ($q) => $q->whereIn('status', self::CLOSED_STATUSES);
    }

    public static function seconds($from, $to = null): ?int
    {
        if (! $from) {
            return null;
        }

        $start = $from instanceof CarbonInterface ? $from : Carbon::parse($from);
        $end = $to ? ($to instanceof CarbonInterface ? $to : Carbon::parse($to)) : now();

        return max(0, $end->getTimestamp() - $start->getTimestamp());
    }

    /** 45s · 12m · 3h 05m · 2d 4h */
    public static function format(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        if ($seconds < 60) {
            return $seconds.'s';
        }
        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return $minutes.'m';
        }
        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            return $hours.'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
        }

        return intdiv($hours, 24).'d '.($hours % 24).'h';
    }
}
