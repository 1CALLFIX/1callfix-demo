<?php

namespace App\Services\Cancellation;

use App\Models\Booking;
use App\Services\CancellationService;
use Illuminate\Support\Carbon;

/**
 * REF 1CF-CANCEL-POLICY-001 — the ONE place that decides whether a CUSTOMER may cancel a Service booking
 * right now, and for what charge. Pure decision: no writes. The customer action, the API quote endpoint,
 * the web page and the post-payment completion all call this, so the rule can never drift between them.
 * (Admin keeps its own override through AdminCancelBookingAction; this never gates an operator.)
 *
 * @phpstan-type Decision array{allowed: bool, code: string, message: string, charge: float, breakdown: ?array, unlocks_at: ?Carbon, free: bool}
 */
class CancellationPolicy
{
    private const BEFORE_WORK = ['pending', 'searching_provider', 'assigned', 'provider_en_route'];

    public function __construct(
        private SparesDelayClock $clock,
        private InterimChargeCalculator $calculator,
        private CancellationService $cancellationService,
    ) {
    }

    /** @return array */
    public function evaluate(Booking $booking, ?Carbon $now = null): array
    {
        $now ??= now();

        if (in_array($booking->status, ['completed', 'cancelled'], true)) {
            return $this->deny('terminal', "This booking is already {$booking->status}.");
        }

        if (in_array($booking->status, self::BEFORE_WORK, true)) {
            return $this->beforeWork($booking, $now);
        }

        if ($booking->status === 'in_progress') {
            return $this->deny(
                'work_in_progress',
                'The professional has started the work, so this booking can no longer be cancelled. If something is wrong, use "My professional left" or contact support.'
            );
        }

        if ($booking->status !== 'on_hold') {
            return $this->deny('not_cancellable', 'This booking cannot be cancelled right now.');
        }

        // ---- on hold ----

        if ($booking->hold_category === 'provider_side') {
            return $this->allow('provider_fault', 'The professional could not continue, so you can cancel free of charge.', 0.0, null, true);
        }

        if ($booking->hold_reason !== 'awaiting_spares') {
            return $this->deny(
                'customer_hold',
                'The job is paused waiting for your decision. Please approve or decline the request — declining extra work lets the job continue at the original price.'
            );
        }

        if ($booking->interim_dispute_status === 'open') {
            return $this->deny('dispute_open', 'You have disputed the professional\'s progress figures. Cancellation will be available as soon as our team has reviewed them.');
        }

        if ($this->clock->providerResumeOverdue($booking, $now)) {
            return $this->allow('provider_delay', 'The spare parts are ready but the professional has not resumed work in time, so you can cancel free of charge.', 0.0, null, true);
        }

        if ($this->clock->unlocked($booking, $now)) {
            $breakdown = $this->calculator->calculate($booking);

            return $this->allow(
                $this->clock->earlyUnlocked($booking, $now) ? 'spares_expected_late' : 'spares_delay',
                'The spare parts are taking too long, so you can cancel. You pay only for the work already done.',
                $breakdown['total'],
                null,
                $breakdown['total'] <= 0,
                $breakdown
            );
        }

        if ($this->clock->customerSupplied($booking)) {
            return $this->deny('customer_supplied', 'The job is waiting for a part you are supplying, so it cannot be cancelled while we wait for it.');
        }

        $days = $this->clock->thresholdDays($booking);
        $at = $this->clock->unlocksAt($booking, $now);

        return $this->deny(
            'spares_locked',
            "The job is waiting for spare parts. You can cancel once the wait passes {$days} days".($at ? ' (from '.$at->timezone(config('app.timezone'))->format('j M Y, g:i A').')' : '').'.',
            $at
        );
    }

    /**
     * Booked-but-not-started stages, all priced from the booking's policy snapshot:
     *   no professional yet            → free
     *   professional late / no-show    → free (and the professional's reliability drops — see CustomerCancelBookingAction)
     *   assigned, not travelling       → `cancellation.assigned_fee` (0 unless an admin sets it)
     *   on the way                     → `cancellation.en_route_fee`
     *   arrived (verified GPS)         → the visit charge
     * A Prime plan whose waiver toggle is on pays neither the en-route nor the visit charge.
     */
    private function beforeWork(Booking $booking, Carbon $now): array
    {
        if ($booking->provider_id === null || in_array($booking->status, ['pending', 'searching_provider'], true)) {
            return $this->allow('before_work', 'You can cancel this booking free of charge.', 0.0, null, true);
        }

        if ($this->providerLate($booking, $now)) {
            return $this->allow('provider_late', 'The professional is running late, so you can cancel free of charge.', 0.0, null, true);
        }

        $price = (float) ($booking->price_quoted ?? 0);
        $waived = app(PrimeWaiver::class)->covers($booking);

        if ($booking->status === 'assigned') {
            $fee = $this->cap((float) PolicySettings::get($booking, 'cancellation.assigned_fee'), $price);

            return $this->allow('assigned', 'You can cancel this booking.', $fee, null, $fee <= 0);
        }

        if ($booking->arrival_verified_at !== null) {
            $fee = $this->calculator->visitFee($booking, $price);

            return $this->allow('visit_charge', 'The professional has arrived. You can cancel; a visit and inspection charge applies.', $fee, null, $fee <= 0, ['code' => 'visit_charge', 'visit_fee' => $fee, 'prime_waived' => $waived]);
        }

        $fee = $waived ? 0.0 : $this->cap((float) PolicySettings::get($booking, 'cancellation.en_route_fee'), $price);

        return $this->allow('en_route', 'The professional is on the way. You can cancel.', $fee, null, $fee <= 0, ['code' => 'en_route', 'en_route_fee' => $fee, 'prime_waived' => $waived]);
    }

    /** Late beyond `cancellation.provider_late_minutes` (snapshot) without a verified arrival. Inactive while the setting is unset. */
    public function providerLate(Booking $booking, ?Carbon $now = null): bool
    {
        $minutes = PolicySettings::get($booking, 'cancellation.provider_late_minutes');
        if ($minutes === null || ! in_array($booking->status, ['assigned', 'provider_en_route'], true) || $booking->arrival_verified_at !== null) {
            return false;
        }

        $reference = $booking->scheduled_at
            ?? $booking->statusHistory()->where('status', 'assigned')->latest('changed_at')->value('changed_at');
        if (! $reference) {
            return false;
        }

        return ($now ?? now())->greaterThan(Carbon::parse($reference)->addMinutes((int) $minutes));
    }

    private function cap(float $fee, float $price): float
    {
        return round(max(0.0, min($fee, $price > 0 ? $price : $fee)), 2);
    }

    /** Plain-language policy shown at booking, checkout, tracking and the hold screen (same numbers the engine uses). */
    /** @param  ?array  $raw  unsaved values keyed by setting key (the admin screen's live preview); null = the booking's snapshot / live settings */
    public function policyLines(?Booking $booking = null, ?array $raw = null): array
    {
        $get = fn (string $key) => $raw !== null ? PolicySettings::cast($key, $raw[$key] ?? null) : ($booking ? PolicySettings::get($booking, $key) : PolicySettings::current($key));
        $days = max(1, (int) $get('cancellation.spares_delay_days'));
        $cap = (int) $get('cancellation.interim_cap_percent');
        $grace = max(1, (int) $get('cancellation.spares_resume_grace_hours'));
        $lines = ['Before a professional is assigned, you can cancel free of charge.'];

        $assigned = (float) $get('cancellation.assigned_fee');
        $lines[] = $assigned > 0
            ? 'Once a professional is assigned, cancelling before they set off costs '.self::money($assigned).'.'
            : 'Once a professional is assigned, you can still cancel free of charge until they set off.';

        $enRoute = (float) $get('cancellation.en_route_fee');
        if ($enRoute > 0) {
            $lines[] = 'If the professional is on the way, cancelling costs '.self::money($enRoute).'.';
        }
        if (($visit = $this->visitChargeText($booking, $raw)) !== null) {
            $lines[] = $visit;
        }
        if ($get('cancellation.provider_late_minutes') !== null) {
            $lines[] = 'If the professional is more than '.$get('cancellation.provider_late_minutes').' minutes late or does not show up, you can cancel free of charge.';
        }

        $lines[] = 'Once the work has started, the booking cannot be cancelled in the middle of the job.';
        $lines[] = "If the job is held up waiting for spare parts for {$days} days or more, you can cancel and pay only for the work already done: the labour completed (never more than {$cap}% of the labour quoted) plus the cost of parts already fitted, backed by a bill or photo. If no work was declared, only the visit fee applies.";
        $lines[] = "If the parts will not arrive for more than {$days} days, you can cancel straight away on the same terms. If the spare is ready but the professional has not resumed within {$grace} hours, you can cancel free of charge.";
        $lines[] = 'If the professional leaves or cannot continue, you can cancel free of charge. A part you choose to supply yourself does not count towards the waiting time.';
        $lines[] = 'You see the exact amount before you confirm, and you can dispute the declared progress for review by our team.';

        return $lines;
    }

    /** "Visit and inspection charge ₹X, adjusted in your final bill if you go ahead with the work." — null while the charge is 0/unset. */
    public function visitChargeText(?Booking $booking = null, ?array $raw = null): ?string
    {
        $get = fn (string $key) => $raw !== null ? PolicySettings::cast($key, $raw[$key] ?? null) : ($booking ? PolicySettings::get($booking, $key) : PolicySettings::current($key));
        $type = $get('cancellation.visit_fee_type');
        $value = (float) $get('cancellation.visit_fee_value');
        if ($value <= 0) {
            return null;
        }

        $amount = $type === 'percent' ? rtrim(rtrim(number_format($value, 2), '0'), '.').'% of the job price' : self::money($value);

        return "Visit and inspection charge {$amount}, adjusted in your final bill if you go ahead with the work.";
    }

    private static function money(float $v): string
    {
        return '₹'.rtrim(rtrim(number_format($v, 2), '0'), '.');
    }

    private function allow(string $code, string $message, float $charge, ?Carbon $unlocksAt, bool $free, ?array $breakdown = null): array
    {
        return ['allowed' => true, 'code' => $code, 'message' => $message, 'charge' => round($charge, 2), 'breakdown' => $breakdown, 'unlocks_at' => $unlocksAt, 'free' => $free];
    }

    private function deny(string $code, string $message, ?Carbon $unlocksAt = null): array
    {
        return ['allowed' => false, 'code' => $code, 'message' => $message, 'charge' => 0.0, 'breakdown' => null, 'unlocks_at' => $unlocksAt, 'free' => false];
    }
}
