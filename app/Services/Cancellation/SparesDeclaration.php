<?php

namespace App\Services\Cancellation;

use App\Models\Booking;
use Illuminate\Support\Carbon;

/**
 * REF 1CF-CANCEL-POLICY-001 — validates and normalises what the professional must declare when putting a job
 * on hold for spares: the one amount for work already done (capped), optional evidence, who sources the part, and the
 * expected arrival date. The amount is cumulative: a re-hold can never declare LESS than an earlier hold did.
 */
final class SparesDeclaration
{
    public const SOURCES = ['provider', 'platform', 'customer'];

    /**
     * @param  array{work_amount?: mixed, expected_at?: mixed, sourced_by?: mixed, evidence?: array}  $input
     * @return array{work_amount: float, expected_at: Carbon, sourced_by: string, evidence: array}
     *
     * @throws \InvalidArgumentException
     */
    public static function normalise(Booking $booking, array $input): array
    {
        // A2: ONE amount for the work already done (labour and parts together), capped by cancellation.interim_cap_percent.
        $amount = $input['work_amount'] ?? null;
        if (! is_numeric($amount) || (float) $amount < 0 || (float) $amount > 10000000) {
            throw new \InvalidArgumentException('Enter the amount for the work already done (0 if none).');
        }
        $amount = round((float) $amount, 2);

        $calculator = app(InterimChargeCalculator::class);
        $cap = $calculator->capValue($booking);
        if ($cap === null) {
            throw new \InvalidArgumentException('The limit for work already done has not been set up yet, so an amount cannot be submitted. Please contact support.');
        }
        if ($amount > $cap) {
            throw new \InvalidArgumentException('The amount cannot be more than ₹'.rtrim(rtrim(number_format($cap, 2), '0'), '.').' — '.PolicySettings::get($booking, 'cancellation.interim_cap_percent').'% of the job price.');
        }

        if ($booking->interim_amount !== null && $amount < (float) $booking->interim_amount) {
            throw new \InvalidArgumentException('The amount cannot be lower than the amount you declared earlier.');
        }

        // Optional bill / photos of the work or parts; kept as evidence, never required.
        $evidence = array_values(array_unique(array_merge((array) ($booking->interim_evidence ?? []), array_filter((array) ($input['evidence'] ?? [])))));

        $source = $input['sourced_by'] ?? null;
        if (! in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException('Say who is getting the spare part: you, 1CallFix or the customer.');
        }

        try {
            $expected = Carbon::parse((string) ($input['expected_at'] ?? ''))->startOfDay();
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Enter the date the spare part is expected.');
        }
        if ($expected->lt(now()->startOfDay())) {
            throw new \InvalidArgumentException('The expected arrival date cannot be in the past.');
        }

        return [
            'work_amount' => $amount,
            'expected_at' => $expected,
            'sourced_by' => $source,
            'evidence' => $evidence,
        ];
    }
}
