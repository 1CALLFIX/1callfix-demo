<?php

namespace App\Services\Ai;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Admin Polish + AI session, Part 2 item 2 — "let an admin type something
 * like 'cancelled bookings in Zone 3 this week' and get a real filtered
 * result... use the LLM (if used) only to map intent to known filter
 * parameters, validated server-side before querying".
 *
 * This deliberately does NOT call an LLM at all. The mapping space here is
 * small and fully enumerable — a fixed booking-status list, a real Zone
 * list, and a handful of relative-date phrasings — the exact case the
 * mission's own item 1 guidance describes for its sibling anomaly
 * detectors ("no new external AI call needed if statistical/deterministic
 * [parsing] does the job"), applied here to intent parsing instead of
 * thresholds. A regex/keyword parser over a known-finite vocabulary is
 * more predictable, faster, fully unit-testable, and has zero dependency
 * on AI availability — exactly what item 4's "AI availability must never
 * become a dependency for a core operational function" asks for, taken to
 * its logical conclusion: the CORE feature (natural-language filtering)
 * simply isn't built on AI at all, not merely "degrades gracefully" when
 * AI is off. Every value this returns is validated against the real
 * $statuses/$zones passed in — never invented, never raw SQL, never
 * handed anywhere near a query builder as a string.
 */
class BookingNaturalLanguageFilter
{
    public const KNOWN_STATUSES = [
        'pending', 'searching_provider', 'assigned', 'provider_en_route',
        'in_progress', 'on_hold', 'completed', 'cancelled', 'disputed',
    ];

    /** Phrases a real admin would actually type for each status — includes the raw enum word itself plus its natural synonyms. */
    private const STATUS_SYNONYMS = [
        'pending' => ['pending'],
        'searching_provider' => ['searching', 'unassigned', 'awaiting provider', 'looking for provider'],
        'assigned' => ['assigned'],
        'provider_en_route' => ['en route', 'on the way', 'on their way'],
        'in_progress' => ['in progress', 'ongoing', 'active'],
        'on_hold' => ['on hold', 'held', 'paused'],
        'completed' => ['completed', 'finished', 'done'],
        'cancelled' => ['cancelled', 'canceled'],
        'disputed' => ['disputed', 'dispute'],
    ];

    /**
     * @param  Collection<int, \App\Models\Zone>  $zones  Real, already-scoped zones the caller may filter by — never a fresh unscoped query run from inside this parser.
     * @return array{status: ?string, zone_id: ?int, date_from: ?Carbon, date_to: ?Carbon, matched: array<int, string>}
     */
    public function parse(string $text, Collection $zones): array
    {
        $normalized = ' '.mb_strtolower(trim($text)).' ';
        $matched = [];

        $status = null;
        foreach (self::STATUS_SYNONYMS as $value => $phrases) {
            foreach ($phrases as $phrase) {
                if (str_contains($normalized, ' '.$phrase.' ') || str_contains($normalized, ' '.$phrase)) {
                    $status = $value;
                    $matched[] = "status: {$value}";
                    break 2;
                }
            }
        }

        $zoneId = null;
        // "zone 3" / "zone3" (by numeric id) OR the zone's own name/display
        // name appearing in the text — both checked against the REAL,
        // already-scoped $zones collection, never a bare numeric id trusted
        // on its own (that would let an admin probe for zone ids outside
        // their own scope by guessing numbers into the search box).
        if (preg_match('/\bzone\s*#?(\d+)\b/i', $normalized, $m)) {
            $candidate = $zones->firstWhere('id', (int) $m[1]);
            if ($candidate) {
                $zoneId = $candidate->id;
                $matched[] = "zone: {$candidate->display_name}";
            }
        }
        if (! $zoneId) {
            foreach ($zones as $zone) {
                if ($zone->name && str_contains($normalized, mb_strtolower($zone->name))) {
                    $zoneId = $zone->id;
                    $matched[] = "zone: {$zone->display_name}";
                    break;
                }
            }
        }

        [$dateFrom, $dateTo, $dateMatch] = $this->parseDateRange($normalized);
        if ($dateMatch) {
            $matched[] = $dateMatch;
        }

        return ['status' => $status, 'zone_id' => $zoneId, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'matched' => $matched];
    }

    /** @return array{0: ?Carbon, 1: ?Carbon, 2: ?string} */
    private function parseDateRange(string $normalized): array
    {
        $now = Carbon::now();

        if (str_contains($normalized, 'today')) {
            return [$now->copy()->startOfDay(), $now->copy()->endOfDay(), 'when: today'];
        }
        if (str_contains($normalized, 'yesterday')) {
            $y = $now->copy()->subDay();
            return [$y->copy()->startOfDay(), $y->copy()->endOfDay(), 'when: yesterday'];
        }
        if (str_contains($normalized, 'this week')) {
            return [$now->copy()->startOfWeek(), $now->copy()->endOfWeek(), 'when: this week'];
        }
        if (str_contains($normalized, 'this month')) {
            return [$now->copy()->startOfMonth(), $now->copy()->endOfMonth(), 'when: this month'];
        }
        if (preg_match('/\blast\s+(\d+)\s+days?\b/i', $normalized, $m)) {
            $days = (int) $m[1];
            return [$now->copy()->subDays($days)->startOfDay(), $now->copy()->endOfDay(), "when: last {$days} days"];
        }

        return [null, null, null];
    }
}
