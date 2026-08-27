<?php

namespace App\Actions;

use App\Exceptions\ModuleNotActiveException;
use App\Jobs\ServiceMatchingJob;
use App\Models\Booking;
use App\Models\FlashSale;
use App\Models\Franchise;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Setting;
use App\Notifications\BookingStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\FlashSaleService;
use App\Services\ModuleActivationService;
use App\Services\Plans\EntitlementService;
use App\Services\WalletService;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;

class CreateBookingAction
{
    public function __construct(
        private EntitlementService $entitlementService,
        private ModuleActivationService $moduleActivation,
        private FlashSaleService $flashSales,
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
     */
    public function execute(array $data): Booking
    {
        $service = Service::findOrFail($data['service_id']);
        $paymentMethod = $data['payment_method'] ?? 'online';

        // Phase 22.1 (Module Activation Foundation) — the real enforcement
        // point PHASE_22_PLATFORM_CAPABILITY_RECOVERY_AUDIT.md §16 named as
        // missing: a stored activation flag that no code ever checked. This
        // is the ONE place every booking (customer app, admin panel, or a
        // Tinker test alike, per this method's own docblock) is created, so
        // it's the right single choke point rather than duplicating the
        // check across every caller. franchise->country_id/city_id are
        // pulled in specifically so a country- or city-level deactivation
        // (which no `franchise_id`-only check could ever see) is honored
        // too, not just franchise/zone.
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
        // resolveAuthoritativePrice() below). That is now the customer path:
        // API\BookingController no longer computes a price of its own, so
        // there is exactly one place a customer booking's price can come
        // from. An EXPLICIT price_quoted is still honoured — that is the
        // admin call-centre form's real, permission-gated negotiated-price
        // feature (Livewire\Bookings\Index::createBooking(), gated on
        // bookings.create), not a client-supplied value: no customer-facing
        // request object accepts a price field at all.
        [$basePrice, $appliedSale] = $this->resolveAuthoritativePrice($data, $service, $scope);

        $booking = DB::transaction(function () use ($data, $service, $paymentMethod, $basePrice, $appliedSale) {
            $booking = Booking::create([
                'franchise_id' => $data['franchise_id'],
                'zone_id' => $data['zone_id'],
                'customer_id' => $data['customer_id'],
                'service_id' => $service->id,
                'address_id' => $data['address_id'],
                'status' => 'pending',
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'price_quoted' => $basePrice,
                'payment_method' => $paymentMethod,
                'customer_note' => $data['customer_note'] ?? null,
            ]);

            // Records that this booking really used the sale — the ONLY place
            // FlashSaleService enforces quantity / per-customer limits
            // against committed usage, and the call its own redeem()
            // docblock (and PHASE_C_DISCOVERY_AND_CATALOG.md item 4) says
            // belongs at booking time. Inside this transaction on purpose:
            // if the sale turns out to be sold out or already used up by
            // this customer, redeem() throws and the booking rolls back
            // rather than silently charging the undiscounted price the
            // customer was never shown.
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
            if ($booking->customer) {
                $adjustment = $this->entitlementService->resolveAndConsumeForBooking($booking->customer, $basePrice, $booking);
                if ($adjustment) {
                    $booking->price_quoted = $adjustment['adjusted_price'];
                    $booking->save();
                }
            }

            if ($paymentMethod === 'wallet') {
                $this->payWithWallet($booking);
            }

            return $booking;
        });

        ServiceMatchingJob::dispatch($booking->id);

        if ($booking->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);
            $booking->customer->notify(new BookingStatusNotification('created', $booking, $channels));
        }

        return $booking;
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

    private function payWithWallet(Booking $booking): void
    {
        $scope = array_filter(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);

        if (Setting::get('payment.wallet_enabled', '1', $scope) !== '1') {
            throw new \RuntimeException('Wallet payments are not enabled.');
        }

        app(WalletService::class)->debit(
            $booking->customer,
            (float) $booking->price_quoted,
            reason: "Payment for booking {$booking->code}",
            ref: "booking:{$booking->id}:wallet-payment"
        );

        // Same role Payment plays for an online booking (captured row
        // CancellationService's refundIfPaid() can find), just gateway =
        // 'wallet' instead of 'razorpay' — no external gateway involved,
        // captured immediately since the debit above already succeeded.
        Payment::create([
            'booking_id' => $booking->id,
            'purpose' => 'booking',
            'amount' => $booking->price_quoted,
            'gateway' => 'wallet',
            'status' => 'captured',
            'captured_at' => now(),
        ]);

        $booking->payment_status = 'paid';
        $booking->save();
    }
}
