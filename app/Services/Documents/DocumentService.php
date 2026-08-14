<?php

namespace App\Services\Documents;

use App\Models\Country;
use App\Models\GeneratedDocument;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Reusable invoice/receipt data builder (mission Phase 7). ONE normalized
 * view-model shape drives ONE Blade template
 * (resources/views/documents/payment.blade.php) for all three Payment
 * purposes this codebase already has (booking / wallet_topup /
 * plan_subscription) — no per-purpose duplicate template, no duplicate
 * rendering engine. Currency/localization reuses the EXISTING
 * `locale.currency_symbol` Setting cascade; dates are converted to the
 * payer's own Country.default_timezone (real, already-existing per-
 * country data), never a new invented locale mechanism.
 */
class DocumentService
{
    public function __construct(private DocumentNumberService $numberService)
    {
    }

    /**
     * @param  string  $type  'invoice' (pending/created) or 'receipt' (captured/paid) — caller decides based on the payment's own status.
     */
    public function forPayment(Payment $payment, string $type, ?User $generatedBy = null): array
    {
        $payment->loadMissing(['booking.customer', 'booking.franchise.country', 'booking.service', 'user.franchise.country', 'planSubscription.plan']);

        $country = $this->resolveCountry($payment);
        $scope = array_filter(['country_id' => $country?->id]);
        $currencySymbol = Setting::get('locale.currency_symbol', '₹', $scope);
        $timezone = $country?->default_timezone ?: config('app.timezone');

        $document = $this->numberService->numberFor($payment, $type, $country, $generatedBy);

        return [
            'number' => $document->number,
            'type' => $type,
            'generated_at' => Carbon::now()->setTimezone($timezone),
            'currency_symbol' => $currencySymbol,
            'payer_name' => $this->resolvePayerName($payment),
            'payer_phone' => $this->resolvePayerPhone($payment),
            'franchise_name' => $payment->booking?->franchise?->name,
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

    private function resolveCountry(Payment $payment): ?Country
    {
        return $payment->booking?->franchise?->country ?? $payment->user?->franchise?->country;
    }

    private function resolvePayerName(Payment $payment): string
    {
        return $payment->booking?->customer?->name ?? $payment->user?->name ?? 'Customer';
    }

    private function resolvePayerPhone(Payment $payment): ?string
    {
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
            default => [['label' => ucfirst(str_replace('_', ' ', $payment->purpose)), 'amount' => (float) $payment->amount]],
        };
    }
}
