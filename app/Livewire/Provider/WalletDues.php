<?php

namespace App\Livewire\Provider;

use App\Contracts\PaymentGateway;
use App\Livewire\Provider\Concerns\InteractsWithProvider;
use App\Models\ProviderCommissionReceivable;
use App\Models\Setting;
use App\Services\PayoutService;
use App\Services\Providers\ProviderStanding;
use App\Services\WalletTopUpService;
use Livewire\Component;

/**
 * Audit 2026-10-15 L8 — "Wallet & dues": the provider sees their balance, unpaid cash commission, the minimum
 * balance and whether they can take jobs (ProviderStanding, the same rule dispatch uses), and can clear what blocks
 * them:
 *   - "Settle from wallet": the existing dues sweep against money already in the wallet;
 *   - "Pay ₹X now": a Razorpay payment of exactly what restores their standing (WalletTopUpService::
 *     requestProviderDuesPayment), credited by the existing top-up webhook, which then runs the same sweep.
 * Nothing here moves money directly.
 */
class WalletDues extends Component
{
    use InteractsWithProvider;

    public string $flashType = 'success';

    public string $flashMessage = '';

    public function settleFromWallet(PayoutService $payouts): void
    {
        $provider = $this->provider();

        try {
            $payouts->settleProviderDebts($provider->id);
            $this->flashType = 'success';
            $this->flashMessage = 'What your wallet could cover has been paid towards your dues.';
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    public function payNow(WalletTopUpService $topUps, PaymentGateway $gateway): void
    {
        $this->reset('flashMessage');

        if (! $gateway->isConfigured()) {
            $this->flashType = 'error';
            $this->flashMessage = 'Online payment is not available right now. Contact your Franchise Office.';

            return;
        }

        try {
            $order = $topUps->requestProviderDuesPayment($this->provider());
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();

            return;
        }

        $this->dispatch('razorpay-open', order: $order)->self();
        $this->flashType = 'success';
        $this->flashMessage = 'Complete the payment. Your dues clear as soon as the payment is confirmed.';
    }

    public function render(ProviderStanding $standing)
    {
        $provider = $this->provider();

        return view('livewire.provider.wallet-dues', [
            'standing' => $standing->snapshot($provider),
            'receivables' => ProviderCommissionReceivable::outstanding()
                ->where('provider_id', $provider->id)
                ->with('booking:id,code')
                ->orderBy('id')
                ->get(),
            'gatewayConfigured' => app(PaymentGateway::class)->isConfigured(),
            'currency' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.provider', ['title' => 'Wallet & dues']);
    }
}
