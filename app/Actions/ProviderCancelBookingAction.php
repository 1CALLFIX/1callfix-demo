<?php

namespace App\Actions;

use App\Models\Booking;
use App\Models\BookingCancellationRequest;
use App\Models\Provider;
use App\Services\AdminOpsAlertService;
use App\Services\Cancellation\CancellationBlockedException;
use App\Services\Cancellation\InterimChargeCalculator;
use App\Services\Cancellation\PolicySettings;
use App\Services\ProviderReliabilityService;
use Illuminate\Support\Facades\Log;

/**
 * REF 1CF-CANCEL-POLICY-001 — a PROFESSIONAL cancels a job before work starts. Three reasons, each with hard guards
 * evaluated under the booking row lock (so nothing can be slipped past):
 *
 *   own_reason          before arrival, for the professional's own reasons — NO charge to the customer, the
 *                       professional's reliability score drops.
 *   customer_unreachable   needs a verified GPS arrival + `no_show_wait_minutes` elapsed + `no_show_call_attempts`
 *                       in-app call attempts; charges the visit fee.
 *   quote_rejected      needs a verified arrival + an in-app quote the customer rejected (or left unanswered for
 *                       `quote_response_minutes`); charges the visit fee. No in-app quote → blocked.
 *
 * The visit fee comes from the booking's policy snapshot (0 for a Prime plan whose waiver is on). A prepaid booking has
 * the fee kept out of the refund; an unpaid one gets a BookingCancellationRequest the customer settles (wallet or Razorpay
 * order — CustomerCancelBookingAction::payOutstandingCharge), flagged to admins after `unpaid_flag_days`. The professional's
 * share (fee minus commission) is credited once, through the existing ledger, as soon as the fee is collected.
 */
class ProviderCancelBookingAction
{
    public const REASONS = ['own_reason', 'customer_unreachable', 'quote_rejected'];

    public function __construct(
        private AdminCancelBookingAction $cancel,
        private InterimChargeCalculator $calculator,
        private ProviderReliabilityService $reliability,
        private CustomerCancelBookingAction $customerCancel,
    ) {
    }

    /**
     * @return array{booking: Booking, charge: float, request: ?BookingCancellationRequest, already: bool}
     *
     * @throws CancellationBlockedException|\InvalidArgumentException
     */
    public function execute(int $bookingId, Provider $provider, string $reason, ?string $note = null): array
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException('Choose a valid cancellation reason.');
        }

        $existing = Booking::find($bookingId);
        if ($existing && $existing->status === 'cancelled' && $existing->cancelled_by_role === 'provider' && $existing->provider_id === $provider->id) {
            return ['booking' => $existing, 'charge' => (float) $existing->cancellation_fee, 'request' => null, 'already' => true];
        }

        $text = match ($reason) {
            'own_reason' => 'Professional cancelled (own reasons)',
            'customer_unreachable' => 'Professional cancelled: customer not home / unreachable',
            'quote_rejected' => 'Professional cancelled: quote not accepted',
        }.(trim((string) $note) !== '' ? ' — '.trim($note) : '');

        $applied = 0.0;
        $resolver = function (Booking $locked) use ($provider, $reason, &$applied) {
            if ($locked->provider_id !== $provider->id) {
                throw new CancellationBlockedException(['message' => 'This booking is not assigned to you.', 'code' => 'not_yours']);
            }

            $applied = $this->charge($locked, $reason);

            return [$applied, ['code' => $reason, 'charge' => $applied, 'visit_fee_paid_by' => $applied > 0 ? 'customer' : null]];
        };

        $cancelled = $this->cancel->execute($bookingId, $text, feeResolver: $resolver, cancelledByRole: 'provider');

        if ($reason === 'own_reason') {
            $this->reliability->penalise($provider, $cancelled, 'provider_cancelled', $this->penaltyPoints(), 'Cancelled before arrival for own reasons');
        }

        $request = null;
        if ($applied > 0) {
            if ($cancelled->payment_status === 'paid' || in_array($cancelled->payment_status, ['partially_refunded', 'refunded'], true)) {
                // Prepaid: the fee was kept out of the refund — collected, so the professional is paid now.
                $this->customerCancel->payProvider($cancelled, $applied);
            } else {
                $request = BookingCancellationRequest::create([
                    'booking_id' => $cancelled->id,
                    'requested_by' => null,
                    'status' => 'awaiting_payment',
                    'total_charge' => $applied,
                    'amount_due' => $applied,
                    'basis' => $cancelled->cancellation_fee_basis,
                    'reason' => $text,
                    'due_by' => now()->addDays(max(1, (int) PolicySettings::get($cancelled, 'cancellation.unpaid_flag_days'))),
                ]);

                try {
                    $cancelled->customer?->notify(new \App\Notifications\BookingStatusNotification(
                        'cancel_payment_due', $cancelled,
                        \App\Notifications\Support\ChannelResolver::resolve(array_filter(['zone_id' => $cancelled->zone_id, 'franchise_id' => $cancelled->franchise_id]))
                    ));
                    app(AdminOpsAlertService::class)->cancellationEvent('cancel_charge_unpaid', $cancelled);
                } catch (\Throwable $e) {
                    Log::warning("Provider-cancel charge notices failed for booking [{$cancelled->id}]: ".$e->getMessage());
                }
            }
        }

        return ['booking' => $cancelled, 'charge' => $applied, 'request' => $request, 'already' => false];
    }

    /** The guard + the charge for one reason. @throws CancellationBlockedException */
    private function charge(Booking $booking, string $reason): float
    {
        $deny = fn (string $code, string $message) => throw new CancellationBlockedException(['message' => $message, 'code' => $code]);

        if ($reason === 'own_reason') {
            if (! in_array($booking->status, ['assigned', 'provider_en_route'], true) || $booking->arrival_verified_at !== null) {
                $deny('too_late', 'You can cancel for your own reasons only before you arrive.');
            }

            return 0.0;
        }

        if ($booking->status !== 'provider_en_route' || $booking->arrival_verified_at === null) {
            $deny('no_arrival', 'You can cancel with the visit charge only after your arrival has been verified at the address.');
        }

        if ($reason === 'customer_unreachable') {
            $wait = PolicySettings::get($booking, 'cancellation.no_show_wait_minutes');
            $attempts = PolicySettings::get($booking, 'cancellation.no_show_call_attempts');
            if ($wait === null || $attempts === null) {
                $deny('not_configured', 'Cancelling for an unreachable customer is not set up yet — contact support.');
            }
            if (now()->lt($booking->arrival_verified_at->copy()->addMinutes((int) $wait))) {
                $deny('wait_not_over', "Please wait {$wait} minutes after arriving before cancelling.");
            }
            if ($booking->callAttempts()->count() < (int) $attempts) {
                $deny('calls_missing', "Log {$attempts} call attempts to the customer in the app first.");
            }
        } else { // quote_rejected
            $quote = $booking->quotes()->latest('id')->first();
            if (! $quote) {
                $deny('no_quote', 'Send your quote through the app first. Without an in-app quote the visit charge cannot be levied.');
            }
            $minutes = PolicySettings::get($booking, 'cancellation.quote_response_minutes');
            $unanswered = $quote->status === 'sent' && $minutes !== null && now()->gte($quote->sent_at->copy()->addMinutes((int) $minutes));
            if ($quote->status === 'accepted') {
                $deny('quote_accepted', 'The customer accepted your quote — carry on with the job.');
            }
            if ($quote->status !== 'rejected' && ! $unanswered) {
                $deny('quote_pending', 'The customer has not rejected the quote yet.');
            }
        }

        return $this->calculator->visitFee($booking, (float) ($booking->price_quoted ?? 0));
    }

    private function penaltyPoints(): int
    {
        return max(0, (int) PolicySettings::current('cancellation.reliability_penalty_points'));
    }
}
