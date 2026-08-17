<?php

namespace App\Services\Documents;

use App\Contracts\Orderable;
use App\Models\Country;
use App\Models\GeneratedDocument;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Reusable invoice/receipt data builder (mission Phase 7). ONE normalized
 * view-model shape drives ONE Blade template
 * (resources/views/documents/payment.blade.php) for all seven Payment
 * purposes this codebase now has — no per-purpose duplicate template, no
 * duplicate rendering engine. Currency/localization reuses the EXISTING
 * `locale.currency_symbol` Setting cascade; dates are converted to the
 * payer's own Country.default_timezone (real, already-existing per-
 * country data), never a new invented locale mechanism.
 *
 * **2026-08-17 extension:** the original three purposes (`booking`/
 * `wallet_topup`/`plan_subscription`) each have a real, materially
 * different shape (`booking` needs a Service name, `plan_subscription`
 * needs a Plan name), so they keep their own explicit branches. The four
 * verticals added since (`parcel_order`/`taxi_ride`/`property_reservation`/
 * `marketplace_order`) all implement `App\Contracts\Orderable` and share
 * the IDENTICAL shape for what a document needs from them (a franchise for
 * country/currency resolution, a customer for payer name/phone, an order
 * code for the line-item label) — resolved generically through that
 * contract via `resolveOrderable()` rather than four hand-written,
 * near-duplicate branches. Before this, these four purposes silently fell
 * through to the generic `default` branch below, with `resolveCountry()`/
 * `resolvePayerName()`/`resolvePayerPhone()` never even attempting to read
 * their franchise/customer at all (degraded country/payer resolution on a
 * real, generated document) — found and fixed as part of this session's
 * hardening pass, not invented scope.
 */
class DocumentService
{
    /** purpose => Payment relation name. Every value here resolves to a model implementing Orderable. */
    private const ORDERABLE_RELATIONS = [
        'parcel_order' => 'parcelOrder',
        'taxi_ride' => 'taxiRide',
        'property_reservation' => 'propertyReservation',
        'marketplace_order' => 'marketplaceOrder',
        'rental_reservation' => 'rentalReservation',
    ];

    public function __construct(private DocumentNumberService $numberService)
    {
    }

    /**
     * @param  string  $type  'invoice' (pending/created) or 'receipt' (captured/paid) — caller decides based on the payment's own status.
     */
    public function forPayment(Payment $payment, string $type, ?User $generatedBy = null): array
    {
        $payment->loadMissing([
            'booking.customer', 'booking.franchise.country', 'booking.service',
            'user.franchise.country', 'planSubscription.plan',
            'parcelOrder.customer', 'parcelOrder.franchise.country',
            'taxiRide.customer', 'taxiRide.franchise.country',
            'propertyReservation.customer', 'propertyReservation.franchise.country',
            'marketplaceOrder.customer', 'marketplaceOrder.franchise.country', 'marketplaceOrder.store',
            'rentalReservation.customer', 'rentalReservation.franchise.country',
        ]);

        $orderable = $this->resolveOrderable($payment);
        $country = $this->resolveCountry($payment, $orderable);
        $scope = array_filter(['country_id' => $country?->id]);
        $currencySymbol = Setting::get('locale.currency_symbol', '₹', $scope);
        $timezone = $country?->default_timezone ?: config('app.timezone');

        $document = $this->numberService->numberFor($payment, $type, $country, $generatedBy);

        return [
            'number' => $document->number,
            'type' => $type,
            'generated_at' => Carbon::now()->setTimezone($timezone),
            'currency_symbol' => $currencySymbol,
            'payer_name' => $this->resolvePayerName($payment, $orderable),
            'payer_phone' => $this->resolvePayerPhone($payment, $orderable),
            'franchise_name' => $payment->booking?->franchise?->name ?? $this->relatedFranchiseName($payment),
            'lines' => $this->linesFor($payment, $currencySymbol),
            'total' => (float) $payment->amount,
            'amount_words_currency_symbol' => $currencySymbol,
            'payment_status' => $payment->status,
            'gateway' => $payment->gateway,
            'gateway_ref' => $payment->gateway_payment_id ?: $payment->gateway_order_id,
            'captured_at' => $payment->captured_at?->setTimezone($timezone),
            'refunded_amount' => (float) ($payment->refunded_amount ?? 0),
        ];
    }

    /** The related Orderable model for a parcel_order/taxi_ride/property_reservation/marketplace_order payment, or null for the three original purposes (which resolve their own relations directly). */
    private function resolveOrderable(Payment $payment): ?Orderable
    {
        $relation = self::ORDERABLE_RELATIONS[$payment->purpose] ?? null;

        return $relation ? $payment->{$relation} : null;
    }

    private function relatedFranchiseName(Payment $payment): ?string
    {
        $relation = self::ORDERABLE_RELATIONS[$payment->purpose] ?? null;

        return $relation ? $payment->{$relation}?->franchise?->name : null;
    }

    private function resolveCountry(Payment $payment, ?Orderable $orderable): ?Country
    {
        if ($orderable) {
            $relation = self::ORDERABLE_RELATIONS[$payment->purpose];

            return $payment->{$relation}?->franchise?->country;
        }

        return $payment->booking?->franchise?->country ?? $payment->user?->franchise?->country;
    }

    private function resolvePayerName(Payment $payment, ?Orderable $orderable): string
    {
        if ($orderable) {
            $relation = self::ORDERABLE_RELATIONS[$payment->purpose];

            return $payment->{$relation}?->customer?->name ?? 'Customer';
        }

        return $payment->booking?->customer?->name ?? $payment->user?->name ?? 'Customer';
    }

    private function resolvePayerPhone(Payment $payment, ?Orderable $orderable): ?string
    {
        if ($orderable) {
            $relation = self::ORDERABLE_RELATIONS[$payment->purpose];

            return $payment->{$relation}?->customer?->phone;
        }

        return $payment->booking?->customer?->phone ?? $payment->user?->phone;
    }

    /** @return array<int, array{label: string, amount: float}> */
    private function linesFor(Payment $payment, string $currencySymbol): array
    {
        return match ($payment->purpose) {
            'booking' => [[
                'label' => $payment->booking ? "Service: {$payment->booking->service?->name} (Booking {$payment->booking->code})" : 'Booking payment',
                'amount' => (float) $payment->amount,
            ]],
            'wallet_topup' => [['label' => 'Wallet top-up', 'amount' => (float) $payment->amount]],
            'plan_subscription' => [[
                'label' => $payment->planSubscription?->plan ? "Subscription: {$payment->planSubscription->plan->name}" : 'Subscription payment',
                'amount' => (float) $payment->amount,
            ]],
            'parcel_order' => [[
                'label' => $payment->parcelOrder ? "Parcel delivery (Order {$payment->parcelOrder->code})" : 'Parcel order payment',
                'amount' => (float) $payment->amount,
            ]],
            'taxi_ride' => [[
                'label' => $payment->taxiRide ? "Taxi ride (Trip {$payment->taxiRide->code})" : 'Taxi ride payment',
                'amount' => (float) $payment->amount,
            ]],
            'property_reservation' => [[
                'label' => $payment->propertyReservation ? "Property reservation (Booking {$payment->propertyReservation->code})" : 'Property reservation payment',
                'amount' => (float) $payment->amount,
            ]],
            'marketplace_order' => [[
                'label' => $payment->marketplaceOrder
                    ? "Order from {$payment->marketplaceOrder->store?->name} (Order {$payment->marketplaceOrder->code})"
                    : 'Marketplace order payment',
                'amount' => (float) $payment->amount,
            ]],
            'rental_reservation' => [[
                'label' => $payment->rentalReservation ? "Rental (Reservation {$payment->rentalReservation->code})" : 'Rental reservation payment',
                'amount' => (float) $payment->amount,
            ]],
            default => [['label' => ucfirst(str_replace('_', ' ', $payment->purpose)), 'amount' => (float) $payment->amount]],
        };
    }
}
