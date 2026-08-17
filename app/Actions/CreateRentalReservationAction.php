<?php

namespace App\Actions;

use App\Exceptions\ModuleNotActiveException;
use App\Models\EquipmentItem;
use App\Models\Franchise;
use App\Models\Payment;
use App\Models\RentalReservation;
use App\Models\Setting;
use App\Models\Vehicle;
use App\Notifications\RentalReservationStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\ModuleActivationService;
use App\Services\RentalAvailabilityService;
use App\Services\WalletService;
use App\Support\Modules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * RENTAL MODULE IMPLEMENTATION -- the shared Vehicle/Equipment engine
 * counterpart to `CreatePropertyReservationAction`. Same module-activation
 * gate, same "create the row, then lock+verify inside the same
 * transaction, roll back together on conflict" shape -- see
 * `RentalAvailabilityService`'s own docblock for why the locking mechanism
 * itself differs from Property's (parent-row lock vs. per-day UNIQUE
 * constraint).
 *
 * Starts at `status = 'pending'` -- confirmation is a deliberately separate
 * step (`ConfirmRentalReservationAction`), matching Property Rental's own
 * precedent (no evidence supports auto-confirm/instant-booking as the sole
 * real behavior for Vehicle/Equipment either).
 */
class CreateRentalReservationAction
{
    public function __construct(
        private ModuleActivationService $moduleActivation,
        private RentalAvailabilityService $availability,
    ) {
    }

    public function execute(array $data): RentalReservation
    {
        $rentalType = $data['rental_type'] ?? null;
        if (! in_array($rentalType, ['vehicle', 'equipment'], true)) {
            throw new \InvalidArgumentException("rental_type must be 'vehicle' or 'equipment'.");
        }

        $rentable = $rentalType === 'vehicle'
            ? Vehicle::findOrFail($data['rentable_id'])
            : EquipmentItem::findOrFail($data['rentable_id']);

        $franchise = Franchise::findOrFail($rentable->franchise_id);
        $scope = [
            'zone_id' => $rentable->zone_id,
            'franchise_id' => $franchise->id,
            'city_id' => $franchise->city_id,
            'country_id' => $franchise->country_id,
        ];
        if (! $this->moduleActivation->isActive(Modules::RENTAL, $scope)) {
            throw new ModuleNotActiveException(Modules::RENTAL);
        }

        $startsAt = Carbon::parse($data['starts_at']);
        $endsAt = Carbon::parse($data['ends_at']);
        if (! $endsAt->gt($startsAt)) {
            throw new \RuntimeException('End time must be after start time.');
        }

        $rentalMode = $this->resolveRentalMode($rentalType, $rentable, $data);
        $durationUnit = $rentable->pricing_unit;
        $durationQuantity = RentalReservation::computeDurationQuantity($startsAt, $endsAt, $durationUnit);

        if ($durationQuantity <= 0) {
            throw new \RuntimeException("Requested duration must be at least one {$durationUnit} unit.");
        }

        $price = $this->quote($data, $rentable, $durationQuantity);
        $paymentMethod = $data['payment_method'] ?? 'online';

        $reservation = DB::transaction(function () use ($data, $rentable, $rentalType, $rentalMode, $startsAt, $endsAt, $durationUnit, $durationQuantity, $price, $paymentMethod, $franchise) {
            $reservation = RentalReservation::create([
                'franchise_id' => $franchise->id,
                'zone_id' => $rentable->zone_id,
                'customer_id' => $data['customer_id'],
                'rental_type' => $rentalType,
                'rentable_type' => get_class($rentable),
                'rentable_id' => $rentable->id,
                'rental_mode' => $rentalMode,
                'driver_field_worker_id' => $data['driver_field_worker_id'] ?? null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'duration_unit' => $durationUnit,
                'duration_quantity' => $durationQuantity,
                'status' => 'pending',
                'price_quoted' => $price,
                'payment_method' => $paymentMethod,
                'deposit_amount' => $rentable->deposit_amount,
                'special_requests' => $data['special_requests'] ?? null,
            ]);

            // The real concurrency-safety step -- see
            // RentalAvailabilityService's own class docblock. A conflict
            // here throws and rolls back the reservation row above too.
            $this->availability->reserveRange($rentable, $startsAt, $endsAt, $reservation->id);

            if ($paymentMethod === 'wallet') {
                $this->payWithWallet($reservation);
            }

            return $reservation;
        });

        if ($reservation->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);
            $reservation->customer->notify(new RentalReservationStatusNotification('created', $reservation, $channels));
        }

        return $reservation;
    }

    /**
     * Vehicle: defaults to 'self_drive' when not given, validated against
     * that specific vehicle's own supported_rental_modes allow-list (not a
     * hardcoded two-value check -- see Vehicle::supportsRentalMode()).
     * Equipment: no mode concept, always null.
     */
    private function resolveRentalMode(string $rentalType, $rentable, array $data): ?string
    {
        if ($rentalType === 'equipment') {
            return null;
        }

        $mode = $data['rental_mode'] ?? 'self_drive';
        if (! $rentable->supportsRentalMode($mode)) {
            throw new \RuntimeException("This vehicle does not support the '{$mode}' rental mode.");
        }

        return $mode;
    }

    private function quote(array $data, $rentable, float $durationQuantity): float
    {
        if (isset($data['price_quoted'])) {
            return (float) $data['price_quoted'];
        }

        $unitPrice = $rentable->discount_price > 0 ? (float) $rentable->discount_price : (float) $rentable->base_price;

        return round($unitPrice * $durationQuantity, 2);
    }

    private function payWithWallet(RentalReservation $reservation): void
    {
        $scope = array_filter(['zone_id' => $reservation->zone_id, 'franchise_id' => $reservation->franchise_id]);

        if (Setting::get('payment.wallet_enabled', '1', $scope) !== '1') {
            throw new \RuntimeException('Wallet payments are not enabled.');
        }

        app(WalletService::class)->debit(
            $reservation->customer,
            (float) $reservation->price_quoted,
            reason: "Payment for rental reservation {$reservation->code}",
            ref: "rental_reservation:{$reservation->id}:wallet-payment"
        );

        Payment::create([
            'rental_reservation_id' => $reservation->id,
            'purpose' => 'rental_reservation',
            'amount' => $reservation->price_quoted,
            'gateway' => 'wallet',
            'status' => 'captured',
            'captured_at' => now(),
        ]);

        $reservation->payment_status = 'paid';
        $reservation->save();
    }
}
