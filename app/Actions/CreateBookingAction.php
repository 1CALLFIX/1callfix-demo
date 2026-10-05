<?php

namespace App\Actions;

use App\Exceptions\BookingContextMismatchException;
use App\Exceptions\CouponException;
use App\Exceptions\ModuleNotActiveException;
use App\Jobs\ServiceMatchingJob;
use App\Models\Address;
use App\Models\Booking;
use App\Models\FlashSale;
use App\Models\Franchise;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Zone;
use App\Notifications\BookingStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\AdminOpsAlertService;
use App\Services\Coupons\CouponService;
use App\Services\Coupons\ServicePromotionContextBuilder;
use App\Services\FlashSaleService;
use App\Services\ModuleActivationService;
use App\Services\Plans\EntitlementService;
use App\Services\ScheduledDispatchService;
use App\Services\WalletService;
use App\Support\Acquisition\AcquisitionContext;
use App\Support\Acquisition\AcquisitionSanitizer;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;

class CreateBookingAction
{
    public function __construct(
        private EntitlementService $entitlementService,
        private ModuleActivationService $moduleActivation,
        private FlashSaleService $flashSales,
        private ScheduledDispatchService $scheduledDispatch,
        private CouponService $coupons,
    ) {
    }

    /**
     * Creates a booking (booking.code is auto-filled by BookingObserver) and
     * immediately queues the dispatch job. This is the entry point M3 hangs off —
     * every booking, from the customer app or a Tinker test alike, goes through here.
     *
     * payment_method = 'wallet' debits the customer's wallet synchronously,
     * inside the same transaction as the booking itself — if the wallet
     * can't cover it (or wallet payments are disabled for this scope), the
     * whole booking rolls back rather than being created unpaid. Re-checked
     * here even though Bookings\Index's form already restricts the
     * dropdown to enabled methods — a direct API call must not be able to
     * bypass that by skipping the UI.
     *
     * The pricing / row-creation / flash-redeem / entitlement body now lives
     * in createWithinTransaction() so the Phase E2 multi-service bundle path
     * can reuse it verbatim; execute() is just that + transaction + wallet +
     * dispatch, exactly as before.
     */
    public function execute(array $data): Booking
    {
        $paymentMethod = $data['payment_method'] ?? 'online';

        $walletPayment = null;

        // REF 1CF-SCHEDULING-DISPATCH-001 (Part 2, PAYMENT GATE) — a
        // scheduled booking's offers must not go out until payment is
        // confirmed, and 'cash' payment_status never reaches 'paid' on its
        // own (no capture event exists for cash), which would silently
        // strand a cash scheduled booking's dispatch forever. Checked
        // before the transaction opens — this is an input-validation
        // rejection, not a booking-state rollback.
        if (! empty($data['scheduled_at']) && $paymentMethod === 'cash') {
            throw new \RuntimeException('Scheduled bookings must be paid online or from wallet — cash on delivery is not available for a scheduled booking.');
        }

        $booking = DB::transaction(function () use ($data, $paymentMethod, &$walletPayment) {
            $booking = $this->createWithinTransaction($data);

            if ($paymentMethod === 'wallet') {
                $walletPayment = $this->payWithWallet($booking);
            }

            return $booking;
        });

        if ($booking->scheduled_at !== null) {
            // Open-offer scheduled dispatch (Part 2) — starts discovery at
            // CREATION, not at scheduled_at minus the buffer, subject only
            // to the payment gate above. releaseIfEligible() is a no-op
            // (booking stays 'pending') until payment_status is 'paid' —
            // for 'wallet' that's already true by the time we get here
            // (payWithWallet() ran inside the transaction above); for
            // 'online' it fires later from RazorpayWebhookHandler instead.
            $this->scheduledDispatch->releaseIfEligible($booking);
        } elseif ($booking->coupon_id !== null && $booking->payment_status !== 'paid') {
            // A coupon booking is a benefit booking: nothing is dispatched until the
            // online payment is captured (RazorpayWebhookHandler -> CouponDispatchGate).
            // If payment never arrives the unpaid-hold sweep cancels it.
        } else {
            ServiceMatchingJob::dispatch($booking->id);
        }

        if ($booking->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);
            $booking->customer->notify(new BookingStatusNotification('created', $booking, $channels));
        }

        // Phase 2 — real-time operational push to opted-in admins.
        app(AdminOpsAlertService::class)->bookingCreated($booking);

        // A wallet-paid booking is captured synchronously here, never
        // through RazorpayWebhookHandler — fire the same payment-captured
        // alert its docblock hook does, once, after the transaction has
        // committed. Online/cash bookings hit this path with $walletPayment
        // still null: online fires from the webhook instead, cash has no
        // capture, so there is no double-fire.
        if ($walletPayment) {
            app(AdminOpsAlertService::class)->paymentCaptured($walletPayment);
        }

        return $booking;
    }

    /**
     * Server-authoritative franchise / zone for a booking: derived from the customer's own address.
     * A caller-supplied franchise_id / zone_id / address_id that disagrees is rejected, not corrected, so a
     * forged context can never reach pricing, module activation, dispatch or the coupon engine. Public so
     * CreateBookingBundleAction derives the bundle wrapper row the same way (one rule, not two copies).
     *
     * @return array{franchise_id: int, zone_id: int|null}
     *
     * @throws BookingContextMismatchException
     */
    public function resolveLocation(array $data): array
    {
        $address = Address::find($data['address_id'] ?? null);
        if (! $address || (int) $address->user_id !== (int) ($data['customer_id'] ?? 0)) {
            throw new BookingContextMismatchException('The address does not belong to this customer.');
        }

        $zone = $address->zone_id ? Zone::find($address->zone_id) : null;
        $franchiseId = $address->franchise_id ?? $zone?->franchise_id;
        if ($franchiseId === null) {
            throw new BookingContextMismatchException('The address is not served by any franchise.');
        }
        if ($zone && (int) $zone->franchise_id !== (int) $franchiseId) {
            throw new BookingContextMismatchException('The address zone does not belong to its franchise.');
        }

        if (isset($data['franchise_id']) && (int) $data['franchise_id'] !== (int) $franchiseId) {
            throw new BookingContextMismatchException('The franchise does not serve this address.');
        }
        if (isset($data['zone_id']) && $zone && (int) $data['zone_id'] !== (int) $zone->id) {
            throw new BookingContextMismatchException('The zone does not match this address.');
        }

        return ['franchise_id' => (int) $franchiseId, 'zone_id' => $zone?->id];
    }

    /**
     * The full booking-creation body — module-activation gate, Phase-D
     * server-authoritative pricing, the `bookings` row itself, flash-sale
     * redemption and the plan-entitlement price adjustment — with NO
     * transaction of its own, NO wallet payment and NO dispatch/notification
     * around it.
     *
     * execute() (the single-service path) wraps this in exactly one
     * DB::transaction + an optional wallet debit + one ServiceMatchingJob —
     * behaviourally unchanged from before this method was extracted. Phase
     * E2's CreateBookingBundleAction wraps ONE outer transaction and ONE
     * aggregate wallet debit around N calls to this instead, so a
     * multi-service bundle is still priced, flash-redeemed and
     * entitlement-adjusted by exactly this code and never a second copy of
     * it (mission E2: "Do not create duplicate pricing logic").
     *
     * MUST be called from inside a DB::transaction — the flash-sale
     * redemption and entitlement ledger writes assume one is already open.
     *
     * @param  array  $data  same shape execute() takes, plus an optional
     *         `booking_bundle_id` the bundle path sets so the child row is
     *         linked at INSERT time (NULL / absent for the single path).
     */
    public function createWithinTransaction(array $data): Booking
    {
        $service = Service::findOrFail($data['service_id']);

        // Hardening §F — ownership context is derived here, never trusted from the caller.
        $data = array_merge($data, $this->resolveLocation($data));

        // Phase 22.1 (Module Activation Foundation) — the real enforcement
        // point PHASE_22_PLATFORM_CAPABILITY_RECOVERY_AUDIT.md §16 named as
        // missing: a stored activation flag that no code ever checked. This
        // is the ONE place every booking (customer app, admin panel, a
        // Tinker test, or a bundle child alike) is created, so it's the
        // right single choke point rather than duplicating the check across
        // every caller. franchise->country_id/city_id are pulled in
        // specifically so a country- or city-level deactivation (which no
        // `franchise_id`-only check could ever see) is honored too, not just
        // franchise/zone.
        $franchise = Franchise::findOrFail($data['franchise_id']);
        $scope = [
            'zone_id' => $data['zone_id'] ?? null,
            'franchise_id' => $franchise->id,
            'city_id' => $franchise->city_id,
            'country_id' => $franchise->country_id,
        ];
        if (! $this->moduleActivation->isActive(Modules::SERVICE, $scope)) {
            throw new ModuleNotActiveException(Modules::SERVICE);
        }

        // Phase D — server-authoritative pricing. A caller that does NOT
        // supply price_quoted gets the price computed here, from the
        // database, using the whole existing cascade (see
        // resolveAuthoritativePrice() below). That is the customer path
        // (API\BookingController and API\BookingBundleController never
        // populate price_quoted from client input), so there is exactly one
        // place a customer booking's price can come from. An EXPLICIT
        // price_quoted is still honoured — the admin call-centre form's
        // real, permission-gated negotiated-price feature
        // (Livewire\Bookings\Index::createBooking(), gated on
        // bookings.create), not a client-supplied value.
        [$basePrice, $appliedSale] = $this->resolveAuthoritativePrice($data, $service, $scope);

        $booking = Booking::create([
            'booking_bundle_id' => $data['booking_bundle_id'] ?? null,
            'franchise_id' => $data['franchise_id'],
            'zone_id' => $data['zone_id'],
            'customer_id' => $data['customer_id'],
            'service_id' => $service->id,
            'address_id' => $data['address_id'],
            'status' => 'pending',
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'price_quoted' => $basePrice,
            'payment_method' => $data['payment_method'] ?? 'online',
            'customer_note' => $data['customer_note'] ?? null,
            // F1 — display/reporting only. One write path: API body first, else the web session.
            'acquisition' => AcquisitionSanitizer::clean($data['acquisition'] ?? null) ?? AcquisitionContext::current(),
        ]);

        // Records that this booking really used the sale — the ONLY place
        // FlashSaleService enforces quantity / per-customer limits against
        // committed usage, and the call its own redeem() docblock (and
        // PHASE_C_DISCOVERY_AND_CATALOG.md item 4) says belongs at booking
        // time. Inside the caller's transaction on purpose: if the sale
        // turns out to be sold out or already used up by this customer,
        // redeem() throws and the whole booking (or bundle) rolls back
        // rather than silently charging the undiscounted price the customer
        // was never shown.
        if ($appliedSale && $booking->customer) {
            $this->flashSales->redeem(
                FlashSale::findOrFail($appliedSale['flash_sale_id']),
                $service,
                $booking->customer,
                (float) $appliedSale['original_price'],
                $booking,
            );
        }

        // Plan Engine: a Customer Prime-style entitlement can adjust the
        // price right here, at booking_created (approved plan §6/§11) —
        // additive to Service.base_price/FranchiseServicePricing, never a
        // parallel pricing path. Null means no applicable/usable plan;
        // today's price stands unchanged.
        $couponCode = trim((string) ($data['coupon_code'] ?? ''));

        if ($booking->customer && $couponCode !== '') {
            $this->applyCouponOrMemberBenefit($booking, $basePrice, $couponCode, $appliedSale !== null);
        } elseif ($booking->customer) {
            $adjustment = $this->entitlementService->resolveAndConsumeForBooking($booking->customer, $basePrice, $booking);
            if ($adjustment) {
                $booking->price_quoted = $adjustment['adjusted_price'];
                $booking->save();
            }
        }

        return $booking;
    }

    /**
     * Coupon engine, decision Q8 — ONE discount per booking. When a coupon
     * code is supplied and the customer also holds a Prime/member pricing
     * benefit, the larger of the two applies (ties go to the member benefit
     * they already own) and the other is never consumed. A coupon never
     * applies to a booking covered by a quantity-redemption entitlement.
     *
     * The coupon is validated and reserved here, inside the booking
     * transaction, so an unusable coupon (including cash, which the engine
     * rejects) fails the whole booking rather than silently charging the
     * undiscounted price the customer was never shown.
     *
     * @throws \App\Exceptions\CouponException
     */
    private function applyCouponOrMemberBenefit(Booking $booking, float $basePrice, string $couponCode, bool $flashApplied): void
    {
        $builder = app(ServicePromotionContextBuilder::class);
        $ctx = $builder->forBooking($booking, $couponCode, $flashApplied);

        $preview = $this->entitlementService->previewBestPricingEntitlement($booking->customer, $basePrice);

        if ($preview && $preview['entitlement_type'] === 'quantity') {
            throw new CouponException('entitlement_covered', 'Your membership benefit already covers this booking.');
        }

        $result = $this->coupons->validate($ctx);
        if (! $result->eligible) {
            throw new CouponException($result->reasonCode, $result->message);
        }

        $memberDiscount = $preview['discount'] ?? 0.0;

        if ($preview && $memberDiscount >= $result->discountTotal) {
            // Member benefit is at least as good: apply it exactly as before and tell the customer which won.
            $adjustment = $this->entitlementService->resolveAndConsumeForBooking($booking->customer, $basePrice, $booking);
            if ($adjustment) {
                $booking->price_quoted = $adjustment['adjusted_price'];
            }
            $booking->coupon_snapshot = [
                'applied' => false,
                'reason' => 'member_benefit_larger',
                'code' => $couponCode,
                'coupon_discount' => $result->discountTotal,
                'member_discount' => $memberDiscount,
                'benefit' => 'membership',
            ];
            $booking->save();

            return;
        }

        [$applied] = $this->coupons->reserve($ctx, $booking);

        $booking->coupon_id = $applied->coupon->id;
        $booking->coupon_discount_amount = $applied->discountTotal;
        $booking->coupon_snapshot = $applied->snapshot + [
            'applied' => true,
            'member_discount_not_used' => $memberDiscount,
        ];
        $booking->save();
    }

    /**
     * The final chargeable amount, and the flash sale (if any) it came from.
     *
     * No pricing arithmetic lives here: it delegates to
     * FlashSaleService::effectivePriceFor(), which is the existing cascade
     * (Service::resolvePrice() -> the flash-sale layer) and nothing else.
     * The scope handed to it is the SAME array this method's caller already
     * built for the module-activation gate, which is exactly the shape
     * AuthorizationService::scopeCovers() takes — so a zone- or franchise-
     * scoped sale is judged against where the booking is actually being
     * placed (the customer's own address), not against anything the caller
     * claimed.
     *
     * @return array{0: float, 1: ?array} [price, applied sale or null]
     */
    private function resolveAuthoritativePrice(array $data, Service $service, array $scope): array
    {
        if (isset($data['price_quoted'])) {
            return [(float) $data['price_quoted'], null];
        }

        $effective = $this->flashSales->effectivePriceFor(
            $service,
            (int) $data['franchise_id'],
            array_filter($scope, fn ($value) => $value !== null),
        );

        return [$effective['price'], $effective['sale']];
    }

    private function payWithWallet(Booking $booking): Payment
    {
        $scope = array_filter(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);

        if (Setting::get('payment.wallet_enabled', null, $scope) !== '1') {
            throw new \RuntimeException('Wallet payments are not enabled.');
        }

        app(WalletService::class)->debit(
            $booking->customer,
            $booking->amountPayable(),
            reason: "Payment for booking {$booking->code}",
            ref: "booking:{$booking->id}:wallet-payment"
        );

        // Same role Payment plays for an online booking (captured row
        // CancellationService's refundIfPaid() can find), just gateway =
        // 'wallet' instead of 'razorpay' — no external gateway involved,
        // captured immediately since the debit above already succeeded.
        $payment = Payment::create([
            'booking_id' => $booking->id,
            'purpose' => 'booking',
            'amount' => $booking->amountPayable(),
            'gateway' => 'wallet',
            'status' => 'captured',
            'captured_at' => now(),
        ]);

        $booking->payment_status = 'paid';
        $booking->save();

        return $payment;
    }
}
