<?php

namespace App\Services\Cancellation;

use App\Models\Booking;

/**
 * REF 1CF-CANCEL-POLICY-001 (A2 final rule) — the charge when a customer leaves a job that was started and then stopped.
 *
 *   work started  => total = the ONE amount the professional declared, never above `cancellation.interim_cap_percent`
 *                    of the job total (price + approved extras). No floor. The visit charge is never added.
 *   no work done  => only the visit / inspection charge.
 *
 * `base` is the quoted price plus any extra work the customer approved (that work is part of the agreed scope).
 */
class InterimChargeCalculator
{
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

    /** The booking total the cap applies to: the quoted price plus the extra work the customer approved. */
    public function jobPrice(Booking $booking): float
    {
        return round((float) $booking->price_quoted
            + (float) $booking->extraItems()->where('status', 'approved')->sum('amount'), 2);
    }

    /**
     * The most the professional may declare for work done: cap % of the job price, from the booking's snapshot.
     * Null = `cancellation.interim_cap_percent` is not configured, so NO amount can be accepted (fail closed).
     */
    public function capValue(Booking $booking): ?float
    {
        $percent = PolicySettings::get($booking, 'cancellation.interim_cap_percent');

        return $percent === null ? null : round($this->jobPrice($booking) * max(0, min(100, (int) $percent)) / 100, 2);
    }

    /** True once the professional has actually begun the work (an amount declared for it / start OTP verified / job was in progress). */
    public function workStarted(Booking $booking): bool
    {
        return $booking->interim_amount !== null
            || $booking->start_otp_verified_at !== null
            || $booking->statusHistory()->where('status', 'in_progress')->exists();
    }

    /** @return array{declared: bool, work_started: bool, base: float, visit_fee: float, cap_percent: ?int, cap_value: ?float, declared_amount: float, labour_charge: float, parts_charge: float, job_price: float, total: float} */
    public function calculate(Booking $booking): array
    {
        $jobPrice = $this->jobPrice($booking);
        $capPercent = PolicySettings::get($booking, 'cancellation.interim_cap_percent');
        $capPercent = $capPercent === null ? null : max(0, min(100, (int) $capPercent));
        $capValue = $capPercent === null ? null : round($jobPrice * $capPercent / 100, 2);

        $declared = $booking->interim_amount !== null;
        $declaredAmount = $declared ? round(max(0.0, (float) $booking->interim_amount), 2) : 0.0;
        $started = $this->workStarted($booking);

        // THUMB RULE (CLAUDE.md): the visit charge exists only when NO work was done. Once work has started it is never added.
        // Work started: the provider's one declared amount, never above the cap (a null cap charges nothing — fail closed),
        // never above the job price, no floor. Work never started: only the no-work visit charge.
        if ($started) {
            $total = $declared && $capValue !== null ? min($declaredAmount, $capValue, $jobPrice) : 0.0;
            $visit = 0.0;
        } else {
            $visit = $this->visitFee($booking, $jobPrice);
            $total = $visit;
        }

        return [
            'declared' => $declared,
            'work_started' => $started,
            'base' => $jobPrice,
            'visit_fee' => $visit,
            'cap_percent' => $capPercent,
            'cap_value' => $capValue,
            'declared_amount' => $declaredAmount,
            'labour_charge' => round($total, 2),
            'parts_charge' => 0.0,
            'job_price' => $jobPrice,
            'total' => round($total, 2),
        ];
    }
}
