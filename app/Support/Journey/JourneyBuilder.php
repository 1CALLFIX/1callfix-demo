<?php

namespace App\Support\Journey;

use Carbon\CarbonInterface;

/**
 * REF 1CF-JOURNEY-001 — turns (journey key, current status, status history, context) into the
 * view model the timeline component and the journey API draw. Pure and stateless: it never
 * writes, and it only reads values the existing state machines already record.
 *
 * What it understands beyond a straight list of steps:
 *   - hold episodes (service jobs): on_hold -> spares available -> resumed, derived from the
 *     status-history notes the hold / spares / resume actions write;
 *   - terminal outcomes (cancelled, disputed) with who / why / fee / refund details;
 *   - "pending" in context: a scheduled booking waiting for payment reads differently from an
 *     immediate booking that has just been created;
 *   - payment and schedule chips (Paid, Payment pending, Scheduled for …).
 *
 * Context keys (all optional, built by JourneyContext::forBooking()):
 *   scheduled_label, provider_name, payment => [status, method, amount_label],
 *   cancel => [reason, fee_label, refund_label]
 */
final class JourneyBuilder
{
    public const SPARES_NOTE = 'Spares available';

    /**
     * @param  iterable<object{status:string, note:?string, changed_at:?CarbonInterface}>  $history  oldest first
     * @param  array<string,mixed>  $context
     * @return array{
     *     key:string, title:string, steps:array<int,array>, terminal:?array, paused:bool,
     *     progress:int, headline:string, hint:string, tone:string, current_status:string, chips:array<int,array>
     * }|null
     */
    public static function build(string $journey, string $status, iterable $history, array $context = []): ?array
    {
        $def = JourneyCatalog::for($journey);
        if ($def === null) {
            return null;
        }

        // Oldest first regardless of how the caller loaded it (stable for equal timestamps).
        $history = collect($history)->sortBy(fn ($h) => $h->changed_at?->getTimestamp() ?? 0)->values();
        $stepKeys = array_keys($def['steps']);
        $isTerminal = isset($def['terminals'][$status]);
        $paused = $def['holdable'] && $status === 'on_hold';
        $actor = $def['actor'] ?? 'professional';
        $pro = ($context['provider_name'] ?? null) ?: 'Your '.$actor;

        // First time each status was reached.
        $firstAt = [];
        $reached = [];
        foreach ($history as $entry) {
            $s = (string) $entry->status;
            $firstAt[$s] ??= $entry->changed_at;
            $reached[$s] = true;
        }

        // Where are we on the main lane?
        $effective = $paused ? 'in_progress' : $status;
        $currentIdx = array_search($effective, $stepKeys, true);
        if ($currentIdx === false) {
            $currentIdx = -1; // terminal / unknown: the furthest main step the history reached
            foreach ($stepKeys as $i => $k) {
                if (isset($reached[$k])) {
                    $currentIdx = $i;
                }
            }
        }
        $lastIdx = count($stepKeys) - 1;
        $finished = ! $isTerminal && $currentIdx === $lastIdx;

        $episodes = $def['holdable'] ? self::episodes($history, $paused) : [];

        $steps = [];
        foreach ($stepKeys as $i => $key) {
            [$label, $hint] = $def['steps'][$key];
            $optional = (bool) ($def['steps'][$key][2] ?? false);

            // An optional step nobody went through is simply not part of this journey.
            if ($optional && ! isset($reached[$key]) && $i !== $currentIdx) {
                continue;
            }
            // After a terminal outcome, steps never reached are dropped.
            if ($isTerminal && $i > $currentIdx) {
                continue;
            }

            $state = match (true) {
                $i < $currentIdx => 'done',
                $i === $currentIdx && ($finished || $isTerminal) => 'done',
                $i === $currentIdx && $paused => 'paused',
                $i === $currentIdx => 'current',
                default => 'upcoming',
            };

            $steps[] = [
                'key' => $key,
                'label' => $label,
                'hint' => self::hint($key, $hint, $state, $pro, $context),
                'state' => $state,
                // A step that was reached earlier but is "upcoming" again (e.g. re-dispatch) shows no time.
                'at' => $state === 'upcoming' ? null : ($firstAt[$key] ?? null),
                'episodes' => $key === 'in_progress' ? $episodes : [],
            ];
        }

        $terminal = null;
        if ($isTerminal) {
            [$tLabel, $tHint, $tone] = $def['terminals'][$status];
            $details = $status === 'cancelled' ? self::cancelDetails($context, $history) : [];
            $terminal = [
                'key' => $status, 'label' => $tLabel, 'hint' => $tHint, 'tone' => $tone,
                'at' => $firstAt[$status] ?? null, 'details' => $details,
            ];
        }

        $total = max(1, count($steps));
        $doneCount = count(array_filter($steps, fn ($s) => $s['state'] === 'done'));
        $progress = $isTerminal
            ? (int) round($doneCount / $total * 100)
            : ($finished ? 100 : (int) round((($doneCount + 0.5) / $total) * 100));

        [$headline, $hint, $tone] = self::headline($def, $steps, $terminal, $paused, $episodes, $finished);

        return [
            'key' => $journey,
            'title' => $def['title'],
            'steps' => $steps,
            'terminal' => $terminal,
            'paused' => $paused,
            'progress' => min(100, max(0, $progress)),
            'headline' => $headline,
            'hint' => $hint,
            'tone' => $tone,
            'current_status' => $status,
            'chips' => $isTerminal || $finished ? self::chips($context, true) : self::chips($context, false),
        ];
    }

    /** JSON-safe form for the API / native apps (Carbon -> ISO 8601). */
    public static function toApi(array $journey): array
    {
        $iso = fn ($d) => $d instanceof CarbonInterface ? $d->toIso8601String() : null;

        $journey['steps'] = array_map(function (array $s) use ($iso) {
            $s['at'] = $iso($s['at']);
            $s['episodes'] = array_map(function (array $e) use ($iso) {
                $e['started_at'] = $iso($e['started_at']);
                $e['spares_at'] = $iso($e['spares_at']);
                $e['resumed_at'] = $iso($e['resumed_at']);
                $e['mini'] = array_map(fn ($m) => ['at' => $iso($m['at'])] + $m, $e['mini']);

                return $e;
            }, $s['episodes']);

            return $s;
        }, $journey['steps']);

        if ($journey['terminal']) {
            $journey['terminal']['at'] = $iso($journey['terminal']['at']);
        }

        return $journey;
    }

    /** Customer-facing sentence for a step, adapted to schedule / payment context and who is acting. */
    private static function hint(string $key, string $base, string $state, string $pro, array $context): string
    {
        $base = str_replace(':pro', $pro, $base);
        $scheduled = $context['scheduled_label'] ?? null;
        $payStatus = $context['payment']['status'] ?? null;

        // "Pending" has two real meanings in this platform (see CreateBookingAction):
        //   scheduled booking -> waits, unpaid, until payment confirms, then dispatch is released;
        //   immediate booking -> a momentary queue state before matching begins.
        if ($key === 'pending' && $scheduled) {
            return $state === 'upcoming'
                ? $base
                : ($payStatus === 'paid'
                    ? "Scheduled for {$scheduled}. We're about to start looking for a professional."
                    : "Scheduled for {$scheduled}. We start looking for a professional as soon as your payment is confirmed.");
        }

        if ($scheduled && in_array($key, ['assigned', 'provider_en_route'], true) && $state !== 'upcoming') {
            return "{$base} Visit scheduled for {$scheduled}.";
        }

        return $base;
    }

    /** @return array<int, array{tone:string,text:string}> */
    private static function chips(array $context, bool $settled): array
    {
        $chips = [];

        if (! $settled && ! empty($context['scheduled_label'])) {
            $chips[] = ['tone' => 'blue', 'text' => 'Scheduled for '.$context['scheduled_label']];
        }

        $pay = $context['payment'] ?? null;
        if ($pay) {
            $amount = $pay['amount_label'] ?? null;
            $chips[] = match ($pay['status'] ?? null) {
                'paid' => ['tone' => 'green', 'text' => $amount ? "Paid {$amount}" : 'Paid'],
                'refunded' => ['tone' => 'green', 'text' => 'Refunded'],
                'pending' => ($pay['method'] ?? null) === 'cash'
                    ? ['tone' => 'slate', 'text' => 'Pay in cash after the job']
                    : ['tone' => 'amber', 'text' => 'Payment pending'],
                'failed' => ['tone' => 'red', 'text' => 'Payment failed'],
                default => null,
            } ?? null;
            $chips = array_values(array_filter($chips));
        }

        return $chips;
    }

    /** @return array<int, string> plain-language lines under a cancellation: why, the fee, the refund */
    private static function cancelDetails(array $context, $history): array
    {
        $cancel = $context['cancel'] ?? [];
        $reason = trim((string) ($cancel['reason'] ?? ''));

        if ($reason === '') {
            $note = (string) optional($history->last(fn ($h) => $h->status === 'cancelled'))->note;
            $reason = trim(preg_replace('/^Cancelled by [a-z ]+:\s*/i', '', $note));
            $reason = trim(preg_replace('/\s*\(cancellation fee:[^)]*\)\s*$/i', '', $reason));
        }

        $lines = [];
        if ($reason !== '') {
            $lines[] = self::friendlyCancelReason($reason);
        }
        $lines[] = ($cancel['fee_label'] ?? null) ? 'Cancellation fee: '.$cancel['fee_label'].'.' : 'No cancellation fee.';
        if (! empty($cancel['refund_label'])) {
            $lines[] = $cancel['refund_label'];
        }

        return $lines;
    }

    private static function friendlyCancelReason(string $reason): string
    {
        return match (true) {
            stripos($reason, 'platform dispatch failure') !== false,
            stripos($reason, 'no provider could be found') !== false => "We couldn't find a professional within the search window.",
            stripos($reason, 'cancelled by customer') === 0 => 'You cancelled this booking.',
            default => rtrim($reason, '.').'.',
        };
    }

    /** @return array<int, array> hold episodes oldest first, each with its mini-steps */
    private static function episodes($history, bool $paused): array
    {
        $episodes = [];

        foreach ($history as $entry) {
            $note = (string) ($entry->note ?? '');
            $status = (string) $entry->status;

            if ($status === 'on_hold' && str_starts_with($note, 'Hold reason:')) {
                $rest = trim(substr($note, strlen('Hold reason:')));
                $reason = trim(explode('—', $rest, 2)[0]);
                $episodes[] = ['reason' => $reason, 'started_at' => $entry->changed_at, 'spares_at' => null, 'resumed_at' => null];
            } elseif ($status === 'on_hold' && str_starts_with($note, self::SPARES_NOTE) && $episodes !== []) {
                $episodes[array_key_last($episodes)]['spares_at'] ??= $entry->changed_at;
            } elseif ($status === 'in_progress' && str_starts_with($note, 'Resumed from hold') && $episodes !== []) {
                $episodes[array_key_last($episodes)]['resumed_at'] ??= $entry->changed_at;
            }
        }

        $lastKey = array_key_last($episodes);

        return array_map(function (array $e, int $i) use ($lastKey, $paused) {
            $open = $paused && $i === $lastKey && $e['resumed_at'] === null;
            $spares = $e['reason'] === 'awaiting_spares';
            $label = JourneyCatalog::HOLD_REASONS[$e['reason']] ?? 'On hold';

            $mini = [['label' => $label, 'state' => ($e['spares_at'] || $e['resumed_at']) ? 'done' : ($open ? 'current' : 'done'), 'at' => $e['started_at']]];
            if ($spares) {
                $mini[] = [
                    'label' => 'Spares available',
                    'state' => $e['spares_at'] ? ($e['resumed_at'] ? 'done' : 'current') : ($e['resumed_at'] ? 'skipped' : 'upcoming'),
                    'at' => $e['spares_at'],
                ];
            }
            $mini[] = ['label' => 'Work resumed', 'state' => $e['resumed_at'] ? 'done' : 'upcoming', 'at' => $e['resumed_at']];

            return $e + ['open' => $open, 'label' => $label, 'mini' => $mini];
        }, $episodes, array_keys($episodes));
    }

    /** @return array{0:string,1:string,2:string} headline, hint, tone */
    private static function headline(array $def, array $steps, ?array $terminal, bool $paused, array $episodes, bool $finished): array
    {
        if ($terminal) {
            return [$terminal['label'], $terminal['hint'], $terminal['tone']];
        }
        if ($paused) {
            $open = collect($episodes)->firstWhere('open', true);
            $label = $open['label'] ?? 'On hold';
            $sparesReady = $open && ($open['spares_at'] ?? null);

            return [
                $sparesReady ? 'Spares available' : 'Job on hold',
                $sparesReady ? 'Spare parts have arrived — your professional will resume shortly.' : $label.'. We will update you as soon as work resumes.',
                'amber',
            ];
        }

        $active = collect($steps)->first(fn ($s) => in_array($s['state'], ['current', 'paused'], true))
            ?? collect($steps)->last(fn ($s) => $s['state'] === 'done')
            ?? ['label' => $def['title'], 'hint' => ''];

        return [$active['label'], $active['hint'], $finished ? 'green' : 'blue'];
    }
}
