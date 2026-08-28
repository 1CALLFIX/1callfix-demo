<?php

namespace App\Actions;

use App\Models\BookingBundle;
use App\Services\BundleSettlementService;

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

    public function __construct(
        private AdminCancelBookingAction $cancelChild,
        private BundleSettlementService $settlement,
    ) {
    }

    /**
     * @return array{bundle: BookingBundle, refunded: float|null}
     *
     * @throws \RuntimeException if the bundle is already terminal (latched
     *         completed/cancelled) — the caller maps this to HTTP 409.
     */
    public function execute(int $bundleId, string $reason): array
    {
        $bundle = BookingBundle::query()->with('children')->findOrFail($bundleId);

        if ($bundle->status !== 'active') {
            throw new \RuntimeException("This booking bundle is already {$bundle->status}.");
        }

        foreach ($bundle->children->reject(fn ($c) => in_array($c->status, self::TERMINAL, true)) as $child) {
            try {
                $this->cancelChild->execute($child->id, $reason, reconcileBundle: false);
            } catch (\RuntimeException $e) {
                // Child already reached a terminal state (a racing cancel /
                // completion between the snapshot above and now). Nothing to
                // do for it; the final reconciliation below still runs.
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
            'bundle' => $fresh->load([
                'children.service.category',
                'children.service.subcategory',
                'children.address',
            ]),
            'refunded' => $refunded,
        ];
    }
}
