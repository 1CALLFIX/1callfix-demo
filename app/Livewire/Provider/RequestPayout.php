<?php

namespace App\Livewire\Provider;

use App\Livewire\Provider\Concerns\InteractsWithProvider;
use App\Models\PaymentAccount;
use App\Models\Payout;
use App\Models\Setting;
use App\Services\Kyc\KycWithdrawalPolicyService;
use App\Services\PayoutService;
use App\Services\WalletService;
use Livewire\Component;

/**
 * Provider self-service payout requests — the deferred "Next planned step"
 * from the Payment Accounts session: a provider picks one of their own
 * VERIFIED PaymentAccounts, enters an amount, and calls the already-built
 * PayoutService::request(). No new payout logic here; this only wires the
 * existing service (debit-first wallet hold, min/max Setting limits, KYC
 * withdrawal restriction, payment-account ownership check) onto a
 * self-service screen the way App\Livewire\Payouts\Manage already does for
 * admins.
 *
 * Third tab alongside Earnings / Payment Accounts (App\Livewire\Provider\
 * Earnings / PaymentAccounts) under the one "Payments" nav branch — see the
 * matching strip in earnings.blade.php and payment-accounts.blade.php.
 *
 * Deliberately stricter than the admin screen on one point, by the user's
 * own explicit call: Payouts\Manage's dropdown lists a payee's unverified
 * accounts too (labelled "(unverified)") because an admin operator is a
 * trusted actor who may already know a transfer went through outside the
 * system. This self-service screen only ever offers the provider's own
 * VERIFIED accounts — enforced both in the query that populates the select
 * (render()) and again in request() itself, since PayoutService::request()
 * only checks ACCOUNT OWNERSHIP (assertPaymentAccountBelongsToPayee), not
 * verification status — that's a self-service-only rule, so it belongs
 * here, not in the shared service every payee type goes through.
 */
class RequestPayout extends Component
{
    use InteractsWithProvider;

    public string $amount = '';

    public ?int $paymentAccountId = null;

    public string $flashType = 'success';

    public string $flashMessage = '';

    public function mount(): void
    {
        $provider = $this->provider();

        $this->paymentAccountId = PaymentAccount::where('user_id', $provider->user_id)
            ->where('is_verified', true)
            ->orderByDesc('is_default')
            ->value('id');
    }

    public function request(PayoutService $service): void
    {
        $provider = $this->provider();

        $this->validate([
            'paymentAccountId' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ], [], ['paymentAccountId' => 'payment account', 'amount' => 'amount']);

        $isOwnVerifiedAccount = PaymentAccount::where('id', $this->paymentAccountId)
            ->where('user_id', $provider->user_id)
            ->where('is_verified', true)
            ->exists();

        if (! $isOwnVerifiedAccount) {
            $this->flashType = 'error';
            $this->flashMessage = 'Select one of your verified payment accounts.';

            return;
        }

        try {
            $service->request('provider', $provider->id, (float) $this->amount, $this->paymentAccountId);

            $this->reset(['amount']);
            $this->flashType = 'success';
            $this->flashMessage = 'Payout requested — the amount is now held from your wallet balance.';
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    public function render(WalletService $wallet, KycWithdrawalPolicyService $kycPolicy, PayoutService $payoutService)
    {
        $provider = $this->provider();
        $scope = $payoutService->payoutScope('provider', $provider->id);

        return view('livewire.provider.request-payout', [
            'walletBalance' => $wallet->balance($provider->user),
            'verifiedAccounts' => PaymentAccount::where('user_id', $provider->user_id)
                ->where('is_verified', true)
                ->orderByDesc('is_default')
                ->get(),
            'minPayout' => (float) Setting::get('wallet.provider_min_payout_amount', '0', $scope),
            'maxPayout' => (float) Setting::get('wallet.provider_max_payout_amount', '0', $scope),
            'kyc' => $kycPolicy->explain($provider, $scope),
            'payouts' => Payout::where('payee_type', 'provider')
                ->where('payee_id', $provider->id)
                ->with('paymentAccount')
                ->latest('id')
                ->limit(20)
                ->get(),
        ])->layout('components.layouts.provider', ['title' => 'Request Payout']);
    }
}
