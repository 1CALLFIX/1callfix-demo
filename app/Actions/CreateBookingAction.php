<?php

namespace App\Actions;

use App\Jobs\ServiceMatchingJob;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Setting;
use App\Notifications\BookingStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\Plans\EntitlementService;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;

class CreateBookingAction
{
    public function __construct(private EntitlementService $entitlementService)
    {
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
        $basePrice = (float) ($data['price_quoted'] ?? $service->base_price);

        $booking = DB::transaction(function () use ($data, $service, $paymentMethod, $basePrice) {
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
