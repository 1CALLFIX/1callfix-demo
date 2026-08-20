<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\Provider;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Support\Collection;

/**
 * Admin Polish + AI session, Part 2 item 1 — "providers with an abnormal
 * cancellation/rejection pattern versus their own baseline". Pure
 * statistical detection over real columns already written by real code
 * paths, no new external AI call (per the mission's own item 1 guidance:
 * "no new external AI call needed if statistical thresholds do the job") —
 *
 * - "Rejection" has no explicit provider-tap-to-decline action anywhere in
 *   this codebase (confirmed by reading every Accept*OfferAction — a
 *   dispatch_attempts row only ever becomes 'accepted' or, via
 *   ServiceMatchingJob::timeoutExpiredAttempts(), 'timeout'). A booking
 *   offer a provider didn't respond to inside the offer window IS a real,
 *   already-recorded implicit decline — the non-response ('timeout') rate
 *   is the honest proxy for "rejection pattern" this data model actually
 *   supports, not a guess.
 * - "Cancellation" is Booking.status='cancelled' among bookings that had
 *   actually reached this provider (provider_id set) — never a booking
 *   cancelled before a provider was ever assigned.
 *
 * Never auto-acts on a finding — read-only, same discipline as
 * StuckBookingService. Thresholds are Setting-driven engineering safety
 * defaults (comparing a provider only against their OWN trailing history,
 * never a fixed "X% is bad" number), not invented business policy — see
 * KNOWN_RISKS_AND_DECISIONS.md for the explicit note that a real business
 * threshold decision is still open.
 */
class ProviderAnomalyService
{
    private const DEFAULT_BASELINE_DAYS = 30;
    private const DEFAULT_ANOMALY_MULTIPLIER = 2.0;
    private const DEFAULT_MIN_SAMPLE_SIZE = 3;

    private function scopeColumns(): array
    {
        return ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
    }

    /** Row-level scoped to what this admin can actually see (operations.view), same AuthorizationService::scopeQuery() convention as StuckBookingService. */
    public function detect(User $user): Collection
    {
        $authz = app(AuthorizationService::class);
        $providers = $authz->scopeQuery(Provider::query(), $user, 'operations.view', $this->scopeColumns())
            ->with('user')
            ->get();

        if ($providers->isEmpty()) {
            return collect();
        }

        $providerIds = $providers->pluck('id');
        $multiplier = (float) Setting::get('operations.anomaly.multiplier', (string) self::DEFAULT_ANOMALY_MULTIPLIER);
        $minSample = (int) Setting::get('operations.anomaly.min_sample_size', (string) self::DEFAULT_MIN_SAMPLE_SIZE);
        $baselineDays = (int) Setting::get('operations.anomaly.baseline_days', (string) self::DEFAULT_BASELINE_DAYS);

        $today = now()->startOfDay();
        $baselineStart = now()->copy()->subDays($baselineDays)->startOfDay();

        // Two grouped aggregate queries total (today, baseline window), not
        // one query per provider — the exact N+1 shape items 44-53 this
        // branch already swept for elsewhere in the admin panel.
        $offerRates = $this->rateByProvider(
            DispatchAttempt::whereIn('provider_id', $providerIds)->whereIn('status', ['accepted', 'timeout']),
            'notified_at', $today, $baselineStart, 'status', 'timeout'
        );

        $cancelRates = $this->rateByProvider(
            Booking::whereIn('provider_id', $providerIds)->whereIn('status', ['completed', 'cancelled', 'disputed']),
            'created_at', $today, $baselineStart, 'status', 'cancelled'
        );

        $anomalies = collect();
        foreach ($providers as $provider) {
            foreach ([
                ['metric' => 'non_response_rate', 'label' => 'not responding to job offers', 'rates' => $offerRates[$provider->id] ?? null],
                ['metric' => 'cancellation_rate', 'label' => 'cancelling assigned bookings', 'rates' => $cancelRates[$provider->id] ?? null],
            ] as $signal) {
                $rates = $signal['rates'];
                if (! $rates || $rates['today_total'] < $minSample) {
                    continue;
                }

                $isAnomalous = $rates['baseline_total'] >= $minSample
                    ? ($rates['baseline_rate'] > 0
                        ? $rates['today_rate'] >= $rates['baseline_rate'] * $multiplier
                        : $rates['today_rate'] > 0) // zero historical baseline — can't multiply by zero, any occurrence today is a break from history
                    : false; // not enough history to have a real baseline yet — don't flag off noise

                if ($isAnomalous) {
                    $anomalies->push([
                        'provider' => $provider,
                        'metric' => $signal['metric'],
                        'label' => $signal['label'],
                        'today_rate' => round($rates['today_rate'] * 100, 1),
                        'baseline_rate' => round($rates['baseline_rate'] * 100, 1),
                        'today_total' => $rates['today_total'],
                    ]);
                }
            }
        }

        return $anomalies->sortByDesc('today_rate')->values();
    }

    /**
     * @return array<int, array{today_rate: float, baseline_rate: float, today_total: int, baseline_total: int}>
     */
    private function rateByProvider($query, string $dateColumn, $today, $baselineStart, string $statusColumn, string $flaggedStatus): array
    {
        $rows = (clone $query)
            ->where($dateColumn, '>=', $baselineStart)
            ->selectRaw("provider_id, {$statusColumn} as status, DATE({$dateColumn}) >= ? as is_today, count(*) as total", [$today->toDateString()])
            ->groupBy('provider_id', $statusColumn, 'is_today')
            ->get();

        $byProvider = [];
        foreach ($rows->groupBy('provider_id') as $providerId => $group) {
            $todayTotal = (int) $group->where('is_today', 1)->sum('total');
            $todayFlagged = (int) $group->where('is_today', 1)->where('status', $flaggedStatus)->sum('total');
            $baselineTotal = (int) $group->sum('total'); // baseline window is inclusive of today's own rows by design — "trailing N days including today" — today's rate is compared against that whole window, not an artificially excluded one
            $baselineFlagged = (int) $group->where('status', $flaggedStatus)->sum('total');

            $byProvider[$providerId] = [
                'today_total' => $todayTotal,
                'today_rate' => $todayTotal > 0 ? $todayFlagged / $todayTotal : 0.0,
                'baseline_total' => $baselineTotal,
                'baseline_rate' => $baselineTotal > 0 ? $baselineFlagged / $baselineTotal : 0.0,
            ];
        }

        return $byProvider;
    }
}
