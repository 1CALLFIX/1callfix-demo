<?php

namespace App\Services\Cancellation;

use App\Models\Booking;
use Illuminate\Support\Carbon;

/**
 * REF 1CF-CANCEL-POLICY-001 — validates and normalises what the professional must declare when putting a job
 * on hold for spares: progress %, parts already fitted (with a bill/photo), who sources the part, and the
 * expected arrival date. Figures are cumulative: a re-hold can never declare LESS than an earlier hold did.
 */
final class SparesDeclaration
{
    public const SOURCES = ['provider', 'platform', 'customer'];

    /**
     * @param  array{progress_percent?: mixed, parts_fitted_cost?: mixed, expected_at?: mixed, sourced_by?: mixed, evidence?: array}  $input
     * @return array{progress_percent: int, parts_fitted_cost: float, expected_at: Carbon, sourced_by: string, evidence: array}
     *
     * @throws \InvalidArgumentException
     */
    public static function normalise(Booking $booking, array $input): array
    {
        $progress = $input['progress_percent'] ?? null;
        if (! is_numeric($progress) || (int) $progress < 0 || (int) $progress > 100) {
            throw new \InvalidArgumentException('Enter the work completed so far as a percentage between 0 and 100.');
        }
        $progress = (int) $progress;

        if ($booking->interim_progress_percent !== null && $progress < (int) $booking->interim_progress_percent) {
            throw new \InvalidArgumentException("Progress cannot be lower than the {$booking->interim_progress_percent}% you declared earlier.");
        }

        $parts = $input['parts_fitted_cost'] ?? 0;
        if ($parts === '' || $parts === null) {
            $parts = 0;
        }
        if (! is_numeric($parts) || (float) $parts < 0 || (float) $parts > 10000000) {
            throw new \InvalidArgumentException('Enter the cost of parts already fitted (0 if none).');
        }
        $parts = round((float) $parts, 2);

        if ($booking->interim_parts_cost !== null && $parts < (float) $booking->interim_parts_cost) {
            throw new \InvalidArgumentException('Parts fitted cannot be lower than the amount you declared earlier.');
        }

        $evidence = array_values(array_unique(array_merge((array) ($booking->interim_evidence ?? []), array_filter((array) ($input['evidence'] ?? [])))));
        if ($parts > 0 && $evidence === []) {
            throw new \InvalidArgumentException('Upload the bill or a photo of the parts already fitted — parts are only charged with proof.');
        }

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
            'progress_percent' => $progress,
            'parts_fitted_cost' => $parts,
            'expected_at' => $expected,
            'sourced_by' => $source,
            'evidence' => $evidence,
        ];
    }
}
