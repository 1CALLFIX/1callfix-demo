<?php

namespace App\Actions;

use App\Exceptions\ModuleNotActiveException;
use App\Jobs\TaxiDispatchJob;
use App\Models\Franchise;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\TaxiRide;
use App\Notifications\Support\ChannelResolver;
use App\Notifications\TaxiRideStatusNotification;
use App\Services\ModuleActivationService;
use App\Services\WalletService;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;

/**
 * Phase 22.6 (Taxi) — the TaxiRide counterpart to CreateParcelOrderAction,
 * same structure (module-activation guard first, DB transaction,
 * wallet-payment branch, dispatch dispatch, notification).
 */
class CreateTaxiRideAction
{
    public function __construct(private ModuleActivationService $moduleActivation)
    {
    }

    public function execute(array $data): TaxiRide
    {
        $franchise = Franchise::findOrFail($data['franchise_id']);
        $scope = [
            'zone_id' => $data['zone_id'] ?? null,
            'franchise_id' => $franchise->id,
            'city_id' => $franchise->city_id,
            'country_id' => $franchise->country_id,
        ];
        if (! $this->moduleActivation->isActive(Modules::TAXI, $scope)) {
            throw new ModuleNotActiveException(Modules::TAXI);
        }

        $paymentMethod = $data['payment_method'] ?? 'online';
        $price = $this->quote($data, $scope);

        $ride = DB::transaction(function () use ($data, $paymentMethod, $price) {
            $ride = TaxiRide::create([
                'franchise_id' => $data['franchise_id'],
                'zone_id' => $data['zone_id'] ?? null,
                'customer_id' => $data['customer_id'],
                'pickup_address_id' => $data['pickup_address_id'],
                'dropoff_address_id' => $data['dropoff_address_id'] ?? null,
                'status' => 'requested',
                'price_quoted' => $price,
                'payment_method' => $paymentMethod,
                'customer_note' => $data['customer_note'] ?? null,
            ]);

            if ($paymentMethod === 'wallet') {
                $this->payWithWallet($ride);
            }

            return $ride;
        });

        TaxiDispatchJob::dispatch($ride->id);

        if ($ride->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $ride->zone_id, 'franchise_id' => $ride->franchise_id]);
            $ride->customer->notify(new TaxiRideStatusNotification('created', $ride, $channels));
        }

        return $ride;
    }

    /**
     * Configurable pricing infrastructure, deliberately NOT a real fare
     * model — same "no invented values" discipline as Parcel's own
     * quote() (KNOWN_RISKS_AND_DECISIONS.md item 31 covers this for both).
     * A real fare needs distance/time, neither known at request time
     * (that's what dispatch/the trip itself determines) — this quotes a
     * flat base fare only; a real per-km/per-min model is a genuine
     * future business decision, not guessed here.
     */
    private function quote(array $data, array $scope): float
    {
        if (isset($data['price_quoted'])) {
            return (float) $data['price_quoted'];
        }

        return (float) Setting::get('taxi.base_fare', '0', $scope);
    }

    private function payWithWallet(TaxiRide $ride): void
    {
        $scope = array_filter(['zone_id' => $ride->zone_id, 'franchise_id' => $ride->franchise_id]);

        if (Setting::get('payment.wallet_enabled', '1', $scope) !== '1') {
            throw new \RuntimeException('Wallet payments are not enabled.');
        }

        app(WalletService::class)->debit(
            $ride->customer,
            (float) $ride->price_quoted,
            reason: "Payment for ride {$ride->code}",
            ref: "taxi_ride:{$ride->id}:wallet-payment"
        );

        Payment::create([
            'taxi_ride_id' => $ride->id,
            'purpose' => 'taxi_ride',
            'amount' => $ride->price_quoted,
            'gateway' => 'wallet',
            'status' => 'captured',
            'captured_at' => now(),
        ]);

        $ride->payment_status = 'paid';
        $ride->save();
    }
}
