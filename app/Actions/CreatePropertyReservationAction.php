<?php

namespace App\Actions;

use App\Exceptions\ModuleNotActiveException;
use App\Models\Franchise;
use App\Models\Payment;
use App\Models\Property;
use App\Models\PropertyReservation;
use App\Models\Setting;
use App\Notifications\PropertyReservationStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\ModuleActivationService;
use App\Services\PropertyAvailabilityService;
use App\Services\WalletService;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;

/**
 * Phase 22.7 (Property Rental) — the PropertyReservation counterpart to
 * CreateParcelOrderAction/CreateTaxiRideAction. Same module-activation
 * gate first, same DB-transaction/wallet-payment shape — but the
 * transaction's real job here is different: locking the requested date
 * range via `PropertyAvailabilityService::reserveDates()` (the actual
 * concurrency-safety mechanism, not dispatch). A conflict there rolls
 * back the whole reservation, exactly like an insufficient wallet balance
 * does for Parcel/Taxi.
 *
 * Starts at `status = 'pending'` — confirmation is a deliberately separate
 * step (`ConfirmPropertyReservationAction`), matching the mission's own
 * given conceptual flow (RESERVATION → PAYMENT → CONFIRMATION) rather than
 * auto-confirming, since no evidence supports an instant-booking-only
 * policy as the sole real behavior.
 */
class CreatePropertyReservationAction
{
    public function __construct(
        private ModuleActivationService $moduleActivation,
        private PropertyAvailabilityService $availability,
    ) {
    }

    public function execute(array $data): PropertyReservation
    {
        $franchise = Franchise::findOrFail($data['franchise_id']);
        $scope = [
            'zone_id' => $data['zone_id'] ?? null,
            'franchise_id' => $franchise->id,
            'city_id' => $franchise->city_id,
            'country_id' => $franchise->country_id,
        ];
        if (! $this->moduleActivation->isActive(Modules::RENTAL, $scope)) {
            throw new ModuleNotActiveException(Modules::RENTAL);
        }

        $property = Property::findOrFail($data['property_id']);

        $this->assertValidDateRange($data['check_in_date'], $data['check_out_date'], $property);

        $numberOfNights = $this->availability->dateRange($data['check_in_date'], $data['check_out_date'])->count();
        $price = $this->quote($data, $property, $numberOfNights);
        $paymentMethod = $data['payment_method'] ?? 'online';

        $reservation = DB::transaction(function () use ($data, $property, $numberOfNights, $price, $paymentMethod) {
            $reservation = PropertyReservation::create([
                'franchise_id' => $property->franchise_id,
                'zone_id' => $property->zone_id,
                'customer_id' => $data['customer_id'],
                'property_id' => $property->id,
                'check_in_date' => $data['check_in_date'],
                'check_out_date' => $data['check_out_date'],
                'number_of_nights' => $numberOfNights,
                'number_of_guests' => $data['number_of_guests'] ?? 1,
                'status' => 'pending',
                'price_quoted' => $price,
                'payment_method' => $paymentMethod,
                'special_requests' => $data['special_requests'] ?? null,
            ]);

            // The real concurrency-safety step -- see
            // PropertyAvailabilityService's own class docblock. A conflict
            // here throws and rolls back the reservation row above too.
            $this->availability->reserveDates($property, $data['check_in_date'], $data['check_out_date'], $reservation->id);

            if ($paymentMethod === 'wallet') {
                $this->payWithWallet($reservation);
            }

            return $reservation;
        });

        if ($reservation->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);
            $reservation->customer->notify(new PropertyReservationStatusNotification('created', $reservation, $channels));
        }

        return $reservation;
    }

    private function assertValidDateRange(string $checkIn, string $checkOut, Property $property): void
    {
        $checkInDate = \Illuminate\Support\Carbon::parse($checkIn)->startOfDay();
        $checkOutDate = \Illuminate\Support\Carbon::parse($checkOut)->startOfDay();

        if (! $checkOutDate->gt($checkInDate)) {
            throw new \RuntimeException('Check-out date must be after check-in date.');
        }

        $nights = $checkInDate->diffInDays($checkOutDate);

        if ($nights < $property->minimum_nights) {
            throw new \RuntimeException("This property requires a minimum stay of {$property->minimum_nights} night(s).");
        }

        if ($property->maximum_nights && $nights > $property->maximum_nights) {
            throw new \RuntimeException("This property allows a maximum stay of {$property->maximum_nights} night(s).");
        }
    }

    /**
     * Real per-date price-override support (verified against Glover's own
     * `Property::getPricingForDates()`) — a `PropertyAvailability` row's
     * `price_override` (e.g. a host's weekend/peak-date rate) wins over
     * the base/discount nightly rate for that specific date.
     */
    private function quote(array $data, Property $property, int $numberOfNights): float
    {
        if (isset($data['price_quoted'])) {
            return (float) $data['price_quoted'];
        }

        $baseNightly = $property->discount_price > 0 ? (float) $property->discount_price : (float) $property->base_price;

        $dates = $this->availability->dateRange($data['check_in_date'], $data['check_out_date']);
        $overrides = \App\Models\PropertyAvailability::where('property_id', $property->id)
            ->whereIn('date', $dates)
            ->whereNotNull('price_override')
            ->pluck('price_override', 'date');

        $total = 0.0;
        foreach ($dates as $date) {
            $total += (float) ($overrides[$date] ?? $baseNightly);
        }

        return round($total, 2);
    }

    private function payWithWallet(PropertyReservation $reservation): void
    {
        $scope = array_filter(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);

        if (Setting::get('payment.wallet_enabled', '1', $scope) !== '1') {
            throw new \RuntimeException('Wallet payments are not enabled.');
        }

        app(WalletService::class)->debit(
            $reservation->customer,
            (float) $reservation->price_quoted,
            reason: "Payment for reservation {$reservation->code}",
            ref: "property_reservation:{$reservation->id}:wallet-payment"
        );

        Payment::create([
            'property_reservation_id' => $reservation->id,
            'purpose' => 'property_reservation',
            'amount' => $reservation->price_quoted,
            'gateway' => 'wallet',
            'status' => 'captured',
            'captured_at' => now(),
        ]);

        $reservation->payment_status = 'paid';
        $reservation->save();
    }
}
