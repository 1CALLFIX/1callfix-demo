<?php

namespace App\Services\Cancellation;

use App\Models\Booking;

/**
 * REF 1CF-CANCEL-POLICY-001 — the interim-work charge when a customer leaves a job held for spares.
 *
 *   labour = max(visit fee, min(base x declared progress %, base x cap %))     (cap default 50)
 *   parts  = declared cost of parts ACTUALLY FITTED, only with a bill/photo uploaded at hold time
 *   total  = min(labour + parts, job price)            — never more than the job is worth
 *
 * No declaration (progress % never entered) => visit fee only; no extra charge, no parts.
 * `base` is the quoted price plus any extra work the customer approved (that work is part of the agreed scope).
 */
class InterimChargeCalculator
{
    public const DEFAULT_CAP_PERCENT = 50;

    public function __construct(private SparesDelayClock $clock)
    {
    }

    /**
     * The visit / inspection charge from the booking's POLICY SNAPSHOT (`cancellation.visit_fee_*`, flat or percent).
     * 0 for a customer whose Prime plan waives visit charges.
     */
    public function visitFee(Booking $booking, float $base): float
    {
        if (app(PrimeWaiver::class)->coversVisitCharge($booking)) {
            return 0.0;
        }

        return $this->standardVisitFee($booking, $base);
    }

    /**
     * The visit charge from the booking's snapshot BEFORE any Prime waiver. The single place the amount is computed:
     * the charge actually levied (visitFee above) and the amount a Prime waiver forgoes (shown to the customer) are
     * both this number, so changing `cancellation.visit_fee_value` moves them together.
     */
    public function standardVisitFee(Booking $booking, float $base): float
    {
        $type = PolicySettings::get($booking, 'cancellation.visit_fee_type');
        $value = (float) PolicySettings::get($booking, 'cancellation.visit_fee_value');

        $fee = $type === 'percent' ? round($base * $value / 100, 2) : $value;

        return round(max(0.0, min($fee, $base)), 2);
    }

    /** @return array{declared: bool, base: float, visit_fee: float, min_labour: float, min_labour_applied: bool, progress_percent: ?int, progress_value: float, cap_percent: int, cap_value: float, labour_charge: float, parts_declared: float, parts_charge: float, parts_evidenced: bool, job_price: float, total: float} */
    public function calculate(Booking $booking): array
    {
        $jobPrice = round((float) $booking->price_quoted
            + (float) $booking->extraItems()->where('status', 'approved')->sum('amount'), 2);

        $capPercent = max(0, min(100, (int) PolicySettings::get($booking, 'cancellation.interim_cap_percent')));
        $visit = $this->visitFee($booking, $jobPrice);

        $declared = $booking->interim_progress_percent !== null;
        $progress = $declared ? max(0, min(100, (int) $booking->interim_progress_percent)) : null;

        $progressValue = $declared ? round($jobPrice * $progress / 100, 2) : 0.0;
        $capValue = round($jobPrice * $capPercent / 100, 2);

        // Work was done (declared progress above zero): the labour for it, never below the separately configured
        // MINIMUM LABOUR charge (cancellation.interim_min_labour, null = no floor) — this is not a visit charge and
        // never reads cancellation.visit_fee_value. No work declared: only the no-work visit charge applies.
        $worked = $declared && $progress > 0;
        $minLabour = round(max(0.0, (float) (PolicySettings::get($booking, 'cancellation.interim_min_labour') ?? 0)), 2);
        $labour = $worked ? max($minLabour, min($progressValue, $capValue)) : $visit;
        $minLabourApplied = $worked && $minLabour > 0 && $minLabour > min($progressValue, $capValue);

        $partsDeclared = round(max(0.0, (float) ($booking->interim_parts_cost ?? 0)), 2);
        $evidenced = ! empty($booking->interim_evidence);
        $parts = ($declared && $evidenced) ? $partsDeclared : 0.0;

        $total = round(min($labour + $parts, $jobPrice), 2);

        return [
            'declared' => $declared,
            'base' => $jobPrice,
            'visit_fee' => $visit,
            'progress_percent' => $progress,
            'progress_value' => $progressValue,
            'cap_percent' => $capPercent,
            'cap_value' => $capValue,
            'min_labour' => $minLabour,
            'min_labour_applied' => $minLabourApplied,
            'labour_charge' => round($labour, 2),
            'parts_declared' => $partsDeclared,
            'parts_charge' => $parts,
            'parts_evidenced' => $evidenced,
            'job_price' => $jobPrice,
            'total' => $total,
        ];
    }
}
