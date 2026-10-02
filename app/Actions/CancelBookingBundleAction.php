<?php

namespace App\Actions;

use App\Models\BookingBundle;
use App\Services\BundleSettlementService;
use App\Services\Cancellation\BundleCancelNeedsConfirmation;

/**
 * Phase E5.1 — customer-initiated cancellation of a WHOLE multi-service
 * bundle. Closes the Gap-1 half of what Phase E7's QA found missing: before
 * this, a bundle child could only be cancelled through the single-booking
 * `POST /api/bookings/{id}/cancel`, which reused
 * `CancellationService::refundIfPaid()` — and that looks up
 * `Payment::where('booking_id', ...)`, which a bundle child never has (E3
 * keeps ONE Payment per bundle), so cancelling a paid bundle child refunded
 * nothing.
 *
 * This action reimplements NO FSM and NO fee logic:
 *   - every still-active child is cancelled through the existing
 *     `AdminCancelBookingAction` (its FSM guard + its per-child cancellation
 *     fee via `CancellationService::calculateFee`), passing
 *     `reconcileBundle: false` so the shared bundle Payment is reconciled
 *     exactly ONCE — here, at the end;
 *   - `BundleSettlementService` then reconciles that one shared Payment
 *     (keep each child's retained amount, refund the rest, guarded against a
 *     double refund) and advances the bundle's stored status latch.
 *
 * Already-terminal children (completed or cancelled) are left untouched — no
 * clawback of a delivered service, no second cancel of an already cancelled
 * one. A child cancelled by a racing request between the snapshot and the
 * loop is skipped rather than erroring.
 */
class CancelBookingBundleAction
{
    /** @var array<int, string> */
    private const TERMINAL = ['completed', 'cancelled'];

    /** Pre-work charges (assigned / on the way / arrived) that are deducted from the shared bundle payment. */
    private const PRE_WORK_CODES = ['assigned', 'en_route', 'visit_charge'];

    public function __construct(
        private CustomerCancelBookingAction $cancelChild,
        private BundleSettlementService $settlement,
    ) {
    }

    /**
     * What a cancel of this bundle would do right now, visit by visit — shown to the customer BEFORE they confirm.
     *
     * @return array{will_cancel: array<int, array>, kept: array<int, array>, token: string, partial: bool, nothing: bool}
     */
    public function preview(int $bundleId): array
    {
        $bundle = BookingBundle::query()->with('children.service')->findOrFail($bundleId);
        $will = [];
        $kept = [];

        foreach ($bundle->children->reject(fn ($c) => in_array($c->status, self::TERMINAL, true)) as $child) {
            $row = ['id' => $child->id, 'code' => $child->code, 'service' => $child->service?->name];
            $decision = $this->cancelChild->quote($child);

            if (! $decision['allowed']) {
                $kept[] = $row + ['reason' => $decision['message']];
            } elseif ($decision['charge'] > 0 && (! in_array($decision['code'], self::PRE_WORK_CODES, true) || $decision['requires_payment'])) {
                // A spares-delay charge, or any charge that needs a separate payment, is confirmed on its own — never slipped into a bulk cancel.
                $kept[] = $row + ['reason' => 'Cancelling this visit carries a charge of '.number_format($decision['charge'], 2).' — cancel it on its own to review and confirm the amount.'];
            } else {
                // Free, or a pre-work charge that comes out of the one shared payment (shown, so it is never a surprise).
                $will[] = $row + ['charge' => $decision['charge']];
            }
        }

        return [
            'will_cancel' => $will,
            'kept' => $kept,
            'token' => hash('sha256', $bundleId.':'.implode(',', array_column($will, 'id'))),
            'partial' => $kept !== [] && $will !== [],
            'nothing' => $will === [],
        ];
    }

    /**
     * @param  ?string  $confirmToken  the token from preview(): REQUIRED when only some visits can be cancelled, so a
     *         partial cancel is never silent. A bundle where every open visit can go needs no token.
     * @return array{bundle: BookingBundle, refunded: float|null}
     *
     * @throws \RuntimeException if the bundle is already terminal (latched
     *         completed/cancelled) — the caller maps this to HTTP 409.
     * @throws BundleCancelNeedsConfirmation when the cancel would be partial and was not confirmed
     */
    public function execute(int $bundleId, string $reason, ?string $confirmToken = null): array
    {
        $bundle = BookingBundle::query()->with('children')->findOrFail($bundleId);

        if ($bundle->status !== 'active') {
            throw new \RuntimeException("This booking bundle is already {$bundle->status}.");
        }

        $preview = $this->preview($bundleId);

        if ($preview['nothing']) {
            throw new \RuntimeException('Nothing in this bundle can be cancelled right now. '.implode(' ', array_map(fn ($k) => "{$k['code']}: {$k['reason']}", $preview['kept'])));
        }
        if ($preview['kept'] !== [] && ! hash_equals($preview['token'], (string) $confirmToken)) {
            throw new BundleCancelNeedsConfirmation($preview);
        }

        $cancelled = 0;
        $kept = array_map(fn ($k) => "{$k['code']}: {$k['reason']}", $preview['kept']);

        foreach ($preview['will_cancel'] as $row) {
            try {
                $this->cancelChild->execute($row['id'], $bundle->customer_id, $reason, reconcileBundle: false, quoteWaived: true);
                $cancelled++;
            } catch (\RuntimeException|\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                // Child already reached a terminal state (a racing cancel / completion), or was locked by a state
                // change between the preview and now. Nothing to do for it; the reconciliation below still runs.
            }
        }

        // ONE reconciliation of the shared bundle Payment + the status latch.
        $refunded = $this->settlement->settleFromChildren($bundleId);

        $fresh = BookingBundle::query()->with('children')->findOrFail($bundleId);
        $fresh->cancellation_note = $reason;
        $fresh->cancellation_fee = round(
            $fresh->children
                ->where('status', 'cancelled')
                ->sum(fn ($c) => (float) ($c->cancellation_fee ?? 0)),
            2,
        );
        $fresh->save();

        return [
            'kept' => $kept,
            'bundle' => $fresh->load([
                'children.service.category',
                'children.service.subcategory',
                'children.address',
            ]),
            'refunded' => $refunded,
        ];
    }
}
