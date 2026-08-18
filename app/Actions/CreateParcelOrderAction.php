<?php

namespace App\Actions;

use App\Exceptions\ModuleNotActiveException;
use App\Jobs\ParcelDispatchJob;
use App\Models\Franchise;
use App\Models\ParcelOrder;
use App\Models\Payment;
use App\Models\Setting;
use App\Notifications\ParcelOrderStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\ModuleActivationService;
use App\Services\WalletService;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;

/**
 * Phase 22.4 (Parcel) — the Parcel counterpart to CreateBookingAction. The
 * single real entry point for every parcel order, mirroring that class's
 * own structure (module-activation guard, DB transaction, wallet-payment
 * branch, dispatch dispatch, notification) closely enough that a reader
 * already familiar with CreateBookingAction can follow this immediately.
 *
 * Module-activation gate is the FIRST thing checked (Phase 22.1) — since
 * `modules.is_implemented` for `parcel` is deliberately left `false` (not
 * flipped by this phase — see PHASE_22_4_PARCEL_DESIGN.md's own "last
 * step, not an early one" instruction), this action refuses to create any
 * real order until a human explicitly ships the module, no matter how
 * complete the rest of this implementation is.
 */
class CreateParcelOrderAction
{
    public function __construct(private ModuleActivationService $moduleActivation)
    {
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

        $paymentMethod = $data['payment_method'] ?? 'online';
        $price = $this->quote($data, $scope);

        $order = DB::transaction(function () use ($data, $paymentMethod, $price) {
            $order = ParcelOrder::create([
                'franchise_id' => $data['franchise_id'],
                'zone_id' => $data['zone_id'] ?? null,
                'customer_id' => $data['customer_id'],
                'pickup_address_id' => $data['pickup_address_id'],
                'dropoff_address_id' => $data['dropoff_address_id'],
                'package_description' => $data['package_description'] ?? null,
                'package_weight_kg' => $data['package_weight_kg'] ?? null,
                'package_size' => $data['package_size'] ?? 'small',
                'status' => 'pending',
                'price_quoted' => $price,
                'payment_method' => $paymentMethod,
                'customer_note' => $data['customer_note'] ?? null,
            ]);

            if ($paymentMethod === 'wallet') {
                $this->payWithWallet($order);
            }

            return $order;
        });

        ParcelDispatchJob::dispatch($order->id);

        if ($order->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);
            $order->customer->notify(new ParcelOrderStatusNotification('created', $order, $channels));
        }

        return $order;
    }

    /**
     * Configurable pricing infrastructure, deliberately NOT a real
     * commercial pricing model — package-pricing tiers are an explicitly
     * named open business decision (PHASE_22_4_PARCEL_DESIGN.md). Every
     * rate below defaults to 0, the same "no invented values" discipline
     * this codebase's own compensation/tip rates already established —
     * a real parcel order costs exactly `parcel.base_fare` (flat) until an
     * admin actually configures per-kg pricing, never a guessed number.
     *
     * Public (P1 Customer Parcel API) so `ParcelOrderController`'s own
     * `POST /parcel-orders/quote` preview endpoint can compute the exact
     * same number a real `execute()` call would charge, without a second
     * pricing implementation — `isset($data['price_quoted'])` still lets
     * an already-trusted internal caller (none exist today) override it,
     * but ParcelOrderController never populates that key from client
     * input, so a customer can never reach this branch.
     */
    public function quote(array $data, array $scope): float
    {
        if (isset($data['price_quoted'])) {
            return (float) $data['price_quoted'];
        }

        $baseFare = (float) Setting::get('parcel.base_fare', '0', $scope);
        $perKgRate = (float) Setting::get('parcel.per_kg_rate', '0', $scope);
        $weight = (float) ($data['package_weight_kg'] ?? 0);

        return round($baseFare + ($perKgRate * $weight), 2);
    }

    private function payWithWallet(ParcelOrder $order): void
    {
        $scope = array_filter(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);

        if (Setting::get('payment.wallet_enabled', '1', $scope) !== '1') {
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
