<?php

namespace App\Actions;

use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\Payment;
use App\Models\Setting;
use App\Notifications\BookingStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Jobs\ServiceMatchingJob;
use App\Services\Payments\BookingBundlePaymentService;
use App\Services\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Phase E2 (Multi-Service Booking — Creation). The single entry point for a
 * customer submitting several services in one go. It creates ONE
 * `booking_bundles` wrapper and ONE child `bookings` row per service, and it
 * does so by reusing the existing engines, not by re-implementing them:
 *
 *   - every child booking is created by CreateBookingAction::createWithinTransaction()
 *     — the same module-activation gate, the same Phase-D authoritative
 *     pricing cascade (FlashSaleService::effectivePriceFor via
 *     resolveAuthoritativePrice), the same flash-sale redemption, the same
 *     plan-entitlement adjustment. No price arithmetic lives in this class.
 *   - the bundle total is the plain SUM of the server-computed child
 *     `price_quoted` values, frozen onto `total_price_quoted` at creation.
 *   - wallet payment, when that is the chosen method, is ONE aggregate
 *     WalletService::debit() for the whole bundle — never one debit per
 *     child — recorded as a single `payments` row (purpose = 'booking_bundle').
 *   - dispatch is the existing per-booking ServiceMatchingJob, queued once
 *     per child, only after the whole transaction has committed.
 *
 * Atomicity: bundle + all children + all flash redemptions + the aggregate
 * wallet debit are one DB::transaction. Any failure (insufficient wallet,
 * a sold-out sale, a deactivated module) rolls the entire thing back — no
 * bundle, no children, no wallet movement, no committed dispatch job.
 *
 * Idempotency: a caller-supplied key (customer-scoped, unique in the DB)
 * makes an exact retry return the original bundle instead of creating a
 * second one. A retry that reuses the key with a materially different body
 * is rejected. See BundleController + the E1 discovery note.
 *
 * Out of scope for E2 (do NOT add here): the bundle Razorpay webhook (E3),
 * provider consolidation / dispatch ranking changes (E4), bundle
 * cancellation / partial completion / refund (E5–E7).
 */
class CreateBookingBundleAction
{
    /** Message surfaced (as a 409) when an idempotency key is reused with a different request body. */
    public const IDEMPOTENCY_CONFLICT_MESSAGE =
        'This idempotency key was already used for a different booking bundle request.';

    /** Child relations every return path eager-loads, so the resource never N+1s. */
    private const CHILD_RELATIONS = ['children.service.category', 'children.service.subcategory', 'children.address'];

    public function __construct(
        private CreateBookingAction $createBooking,
        private WalletService $wallet,
        private BookingBundlePaymentService $bundlePayments,
    ) {
    }

    /**
     * @param  array{
     *     customer_id: int,
     *     payment_method: string,
     *     idempotency_key: ?string,
     *     request_fingerprint: string,
     *     children: array<int, array{service_id:int, franchise_id:int, zone_id:int, address_id:int, scheduled_at:?string, customer_note:?string}>
     * }  $data  Already authorised + context-derived by BookingBundleController:
     *          customer_id is the authenticated user, franchise_id/zone_id are
     *          derived from each child's own owned address, every service is
     *          verified active. No price / customer_id / franchise_id / zone_id
     *          from this array is ever client-authoritative.
     */
    public function execute(array $data): BookingBundle
    {
        $paymentMethod = $data['payment_method'] ?? 'online';
        $customerId = (int) $data['customer_id'];
        $key = $data['idempotency_key'] ?? null;
        $fingerprint = $data['request_fingerprint'];
        $children = $data['children'];

        // ── Idempotency short-circuit — before any write ──────────────────
        if ($key !== null) {
            $existing = BookingBundle::where('customer_id', $customerId)
                ->where('idempotency_key', $key)
                ->first();

            if ($existing) {
                return $this->replay($existing, $fingerprint);
            }
        }

        // The wrapper row carries a single franchise/zone/address (E1 schema);
        // it takes the first child's, while each child booking keeps its own
        // address-derived franchise/zone. In the normal case (one customer,
        // one address, several services) these are all identical anyway.
        $anchor = $children[0];

        try {
            $bundle = DB::transaction(function () use ($children, $anchor, $customerId, $paymentMethod, $key, $fingerprint) {
                $bundle = BookingBundle::create([
                    'idempotency_key' => $key,
                    'request_fingerprint' => $key !== null ? $fingerprint : null,
                    'franchise_id' => $anchor['franchise_id'],
                    'zone_id' => $anchor['zone_id'],
                    'customer_id' => $customerId,
                    'address_id' => $anchor['address_id'],
                    'status' => 'active',
                    'total_price_quoted' => 0,
                    'payment_status' => 'pending',
                    'payment_method' => $paymentMethod,
                ]);

                $childIds = [];
                $total = 0.0;

                foreach ($children as $child) {
                    $booking = $this->createBooking->createWithinTransaction([
                        'booking_bundle_id' => $bundle->id,
                        'franchise_id' => $child['franchise_id'],
                        'zone_id' => $child['zone_id'],
                        'customer_id' => $customerId,
                        'service_id' => $child['service_id'],
                        'address_id' => $child['address_id'],
                        'scheduled_at' => $child['scheduled_at'] ?? null,
                        'payment_method' => $paymentMethod,
                        'customer_note' => $child['customer_note'] ?? null,
                        // deliberately NO price_quoted — the server computes it
                    ]);

                    $childIds[] = $booking->id;
                    $total += (float) $booking->price_quoted;
                }

                $bundle->total_price_quoted = round($total, 2);
                $bundle->save();

                // ONE aggregate debit for the whole bundle — never per child.
                if ($paymentMethod === 'wallet') {
                    $this->payBundleWithWallet($bundle);
                }

                $bundle->setRelation('children', Booking::whereIn('id', $childIds)->orderBy('id')->get());

                return $bundle;
            });
        } catch (QueryException $e) {
            // unique(customer_id, idempotency_key) lost a race to a concurrent
            // identical request — return that winner rather than erroring.
            if ($key !== null && ($winner = BookingBundle::where('customer_id', $customerId)->where('idempotency_key', $key)->first())) {
                return $this->replay($winner, $fingerprint);
            }
            throw $e;
        }

        // ── After commit: the normal per-booking dispatch + notification ──
        $bundle->loadMissing(self::CHILD_RELATIONS);

        foreach ($bundle->children as $child) {
            ServiceMatchingJob::dispatch($child->id);
        }

        foreach ($bundle->children as $child) {
            if ($child->customer) {
                $channels = ChannelResolver::resolve(['zone_id' => $child->zone_id, 'franchise_id' => $child->franchise_id]);
                $child->customer->notify(new BookingStatusNotification('created', $child, $channels));
            }
        }

        return $bundle;
    }

    /**
     * An existing bundle found for this (customer, key): return it if the
     * request body matches, otherwise reject. The returned instance's
     * `wasRecentlyCreated` is false, which BookingBundleController uses to
     * answer 200 (replay) rather than 201 (fresh create).
     */
    private function replay(BookingBundle $existing, string $fingerprint): BookingBundle
    {
        if ($existing->request_fingerprint !== $fingerprint) {
            throw new \RuntimeException(self::IDEMPOTENCY_CONFLICT_MESSAGE);
        }

        return $existing->loadMissing(self::CHILD_RELATIONS);
    }

    /**
     * The bundle counterpart of CreateBookingAction::payWithWallet(): the
     * same wallet-enabled scope gate, the same WalletService::debit() (the
     * one and only wallet engine — no balance arithmetic here), and the same
     * captured `payments` row, just keyed to booking_bundle_id with
     * purpose = 'booking_bundle' and gateway = 'wallet'. Child rows keep
     * payment_status = 'pending' in E2 — per-child settlement / refund is a
     * later E-step; what E2 guarantees is that the aggregate charge is
     * atomic with the bundle it pays for.
     */
    private function payBundleWithWallet(BookingBundle $bundle): void
    {
        $scope = array_filter(['zone_id' => $bundle->zone_id, 'franchise_id' => $bundle->franchise_id]);

        if (Setting::get('payment.wallet_enabled', '1', $scope) !== '1') {
            throw new \RuntimeException('Wallet payments are not enabled.');
        }

        $this->wallet->debit(
            $bundle->customer,
            (float) $bundle->total_price_quoted,
            reason: "Payment for booking bundle {$bundle->code}",
            ref: "booking_bundle:{$bundle->id}:wallet-payment"
        );

        Payment::create([
            'booking_bundle_id' => $bundle->id,
            'purpose' => 'booking_bundle',
            'amount' => $bundle->total_price_quoted,
            'gateway' => 'wallet',
            'status' => 'captured',
            'captured_at' => now(),
        ]);

        // Phase E3 — mark the bundle (and, unlike E2, every child booking)
        // paid through the ONE shared helper the Razorpay webhook also uses,
        // so a wallet-paid and a gateway-paid bundle end in the same state.
        $this->bundlePayments->markBundlePaid($bundle);
    }
}
