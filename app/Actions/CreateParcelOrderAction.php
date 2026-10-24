<?php

namespace App\Actions;

use App\Exceptions\ModuleNotActiveException;
use App\Exceptions\ParcelQuoteException;
use App\Jobs\ParcelDispatchJob;
use App\Models\Address;
use App\Models\Franchise;
use App\Models\ParcelOrder;
use App\Models\Payment;
use App\Models\Setting;
use App\Notifications\ParcelOrderStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\ModuleActivationService;
use App\Services\Parcel\ParcelPricing;
use App\Services\WalletService;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;

/**
 * Phase 22.4 (Parcel) — the Parcel counterpart to CreateBookingAction. The
 * single real entry point for every parcel order (customer web, customer
 * API, admin call-centre screen), mirroring that class's own structure
 * (module-activation guard, DB transaction, wallet-payment branch,
 * dispatch, notification).
 *
 * Module-activation gate is the FIRST thing checked (Phase 22.1): parcel is
 * implemented, but stays off in every location until an admin activates it
 * on the Modules screen.
 *
 * Parcel completion (2026-10-23):
 *  - Price comes from ParcelPricing (distance + weight + size, all admin
 *    settings) using the two addresses' map pins — never client input.
 *  - Payment: 'wallet' is debited here and the order dispatches at once;
 *    'cash' dispatches at once and the rider collects at pickup; 'online'
 *    is HELD (status pending, no riders offered) until the Razorpay capture
 *    webhook calls ParcelPaymentService::markPaid(), which dispatches it.
 *  - THUMB RULE (online payment only for all benefits): parcel offers no
 *    coupon, discount or credit of any kind, so a cash parcel can never
 *    carry a benefit. A coupon code is refused outright rather than
 *    silently ignored.
 */
class CreateParcelOrderAction
{
    public const PAYMENT_METHODS = ['online', 'wallet', 'cash'];

    public function __construct(
        private ModuleActivationService $moduleActivation,
        private ParcelPricing $pricing,
    ) {
    }

    public function execute(array $data): ParcelOrder
    {
        $franchise = Franchise::findOrFail($data['franchise_id']);
        $scope = [
            'zone_id' => $data['zone_id'] ?? null,
            'franchise_id' => $franchise->id,
            'city_id' => $franchise->city_id,
            'country_id' => $franchise->country_id,
        ];
        if (! $this->moduleActivation->isActive(Modules::PARCEL, $scope)) {
            throw new ModuleNotActiveException(Modules::PARCEL);
        }

        if (! empty($data['coupon_code'])) {
            throw new \RuntimeException('Coupons and offers are not available on parcel delivery.');
        }

        $paymentMethod = $data['payment_method'] ?? 'online';
        if (! in_array($paymentMethod, self::PAYMENT_METHODS, true)) {
            throw new \RuntimeException("Payment method '{$paymentMethod}' is not available for parcel delivery.");
        }

        // Pay at: Pickup | Drop (owner decision 2026-10-24), cash only. Drop = the recipient pays the rider at the
        // door. Nothing waits for the recipient; a refusal at the door is the rider's (ParcelRecipientRefusedAction).
        $cashCollectAt = $paymentMethod === 'cash' && ($data['cash_collect_at'] ?? 'pickup') === 'drop' ? 'drop' : 'pickup';
        if ($cashCollectAt === 'drop' && blank($data['recipient_phone'] ?? null)) {
            throw new \RuntimeException("Enter the recipient's phone number. They pay the rider at the drop.");
        }

        $quote = $this->quote($data, $scope);

        $order = DB::transaction(function () use ($data, $paymentMethod, $cashCollectAt, $quote) {
            $order = ParcelOrder::create([
                'franchise_id' => $data['franchise_id'],
                'zone_id' => $data['zone_id'] ?? null,
                'customer_id' => $data['customer_id'],
                'pickup_address_id' => $data['pickup_address_id'],
                'dropoff_address_id' => $data['dropoff_address_id'],
                'pickup_contact_name' => $data['pickup_contact_name'] ?? null,
                'pickup_contact_phone' => $data['pickup_contact_phone'] ?? null,
                'recipient_name' => $data['recipient_name'] ?? null,
                'recipient_phone' => $data['recipient_phone'] ?? null,
                'package_description' => $data['package_description'] ?? null,
                'package_weight_kg' => $data['package_weight_kg'] ?? null,
                'package_size' => $data['package_size'] ?? 'small',
                'distance_km' => $quote['distance_km'],
                'status' => 'pending',
                'price_quoted' => $quote['total'],
                'price_breakdown' => $quote['lines'],
                // Explicit, not left to the column default: callers read isAwaitingOnlinePayment() on the
                // returned model straight away (the web page opens Razorpay from it).
                'payment_status' => 'pending',
                'payment_method' => $paymentMethod,
                'cash_collect_at' => $cashCollectAt,
                'customer_note' => $data['customer_note'] ?? null,
            ]);

            if ($paymentMethod === 'wallet') {
                $this->payWithWallet($order);
            }

            return $order;
        });

        // An online order is dispatched by ParcelPaymentService::markPaid() once the payment is captured.
        if ($paymentMethod !== 'online') {
            self::dispatchAndNotify($order);
        }

        return $order;
    }

    /** Start the rider search and tell the customer. Called at creation (wallet/cash) or on capture (online). */
    public static function dispatchAndNotify(ParcelOrder $order): void
    {
        ParcelDispatchJob::dispatch($order->id);

        if ($order->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);
            $order->customer->notify(new ParcelOrderStatusNotification('created', $order, $channels));
        }
    }

    /**
     * The price for this order, from ParcelPricing and the two saved addresses' pins.
     * `price_quoted` lets an already-trusted internal caller fix the price (none of the customer paths ever
     * populate it from client input).
     *
     * @return array{total: float, distance_km: ?float, lines: array}
     *
     * @throws ParcelQuoteException
     */
    public function quote(array $data, array $scope): array
    {
        if (isset($data['price_quoted'])) {
            return ['total' => round((float) $data['price_quoted'], 2), 'distance_km' => null, 'lines' => [['label' => 'Fixed price', 'amount' => round((float) $data['price_quoted'], 2)]]];
        }

        $pickup = Address::findOrFail($data['pickup_address_id']);
        $dropoff = Address::findOrFail($data['dropoff_address_id']);

        return $this->pricing->quote(
            (float) $pickup->lat, (float) $pickup->lng, (float) $dropoff->lat, (float) $dropoff->lng,
            isset($data['package_weight_kg']) ? (float) $data['package_weight_kg'] : null,
            $data['package_size'] ?? 'small',
            $scope,
        );
    }

    private function payWithWallet(ParcelOrder $order): void
    {
        $scope = array_filter(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);

        if (Setting::get('payment.wallet_enabled', null, $scope) !== '1') {
            throw new \RuntimeException('Wallet payments are not enabled.');
        }

        app(WalletService::class)->debit(
            $order->customer,
            (float) $order->price_quoted,
            reason: "Payment for parcel order {$order->code}",
            ref: "parcel_order:{$order->id}:wallet-payment"
        );

        Payment::create([
            'parcel_order_id' => $order->id,
            'purpose' => 'parcel_order',
            'amount' => $order->price_quoted,
            'gateway' => 'wallet',
            'status' => 'captured',
            'captured_at' => now(),
        ]);

        $order->payment_status = 'paid';
        $order->save();
    }
}
