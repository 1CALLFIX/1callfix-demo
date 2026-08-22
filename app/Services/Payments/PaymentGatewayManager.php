<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\PaymentGatewayConfig;

/**
 * Payment Gateway Manager session — resolves the PaymentGateway consumers
 * actually get. Bound in AppServiceProvider::register() as the sole source
 * of App\Contracts\PaymentGateway, so every existing consumer
 * (PaymentController, WalletTopUpService, SubscriptionService,
 * CancellationService, the Settings\Manage/Payments\Index admin screens)
 * goes through this transparently -- none of them changed to make that
 * true, because none of them ever depended on a concrete class, only the
 * interface.
 *
 * Resolution: the highest-`priority` ACTIVE `payment_gateways` row whose
 * driver is actually safe to hand out (ACTIVATABLE_DRIVERS below); ties
 * broken by id ascending (first configured wins). If none exists -- true
 * of every environment on day one, since the table starts empty -- this
 * falls back to RazorpayPaymentDriver constructed with NO credentials,
 * which itself falls back to config('services.razorpay.*'): the exact
 * pre-existing behaviour, unchanged.
 */
class PaymentGatewayManager
{
    /**
     * Drivers this manager will actually hand out as the active gateway,
     * even if a row is flagged is_active in the DB. Paytm/PhonePe rows can
     * exist and be toggled (an admin may be staging credentials ahead of
     * merchant onboarding), but stay off this list until onboarding is
     * actually done -- a second, backend-enforced guard under the admin
     * screen's own activation check (PaymentGateways\Manage::activate()),
     * so the two can never disagree.
     */
    public const ACTIVATABLE_DRIVERS = ['razorpay'];

    public function active(): PaymentGateway
    {
        $row = PaymentGatewayConfig::where('is_active', true)
            ->whereIn('driver', self::ACTIVATABLE_DRIVERS)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->first();

        if (! $row) {
            // Legacy env-config path -- today's exact behaviour, byte for
            // byte, for every environment that hasn't configured a
            // gateway in the new admin screen yet.
            return app(RazorpayPaymentDriver::class);
        }

        return $this->driverFor($row);
    }

    /**
     * Every driver this platform knows about (including the two stubs),
     * for the admin screen's driver dropdown -- NOT filtered by
     * ACTIVATABLE_DRIVERS, since an admin can add/stage a Paytm or PhonePe
     * row ahead of activation even though it can't go live yet.
     *
     * @return array<string, string> driver slug => display name
     */
    public function knownDrivers(): array
    {
        return [
            'razorpay' => 'Razorpay',
            'paytm' => 'Paytm',
            'phonepe' => 'PhonePe',
        ];
    }

    private function driverFor(PaymentGatewayConfig $row): PaymentGateway
    {
        $creds = $row->credentials ?? [];

        return match ($row->driver) {
            'razorpay' => new RazorpayPaymentDriver(
                $creds['key_id'] ?? null,
                $creds['key_secret'] ?? null,
                $creds['webhook_secret'] ?? null,
            ),
            'paytm' => new PaytmPaymentDriver(
                $creds['merchant_id'] ?? null,
                $creds['merchant_key'] ?? null,
                $creds['website'] ?? null,
            ),
            'phonepe' => new PhonePePaymentDriver(
                $creds['merchant_id'] ?? null,
                $creds['salt_key'] ?? null,
                $creds['salt_index'] ?? null,
            ),
            // Unrecognized driver value (shouldn't happen -- the admin
            // screen only ever writes a known driver slug) -- same safe
            // fallback as "no active row", never a hard crash on
            // resolution for code paths that don't even touch payments.
            default => app(RazorpayPaymentDriver::class),
        };
    }
}
