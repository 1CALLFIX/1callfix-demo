<?php

namespace App\Livewire\Customer\Wallet;

use App\Contracts\PaymentGateway;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use App\Services\WalletTopUpService;
use Livewire\Component;

/**
 * Phase E6 — wallet balance + ledger + top-up.
 *
 * balance / ledger are read straight from App\Services\WalletService and the
 * wallet_transactions rows — no arithmetic here. Top-up delegates to
 * App\Services\WalletTopUpService::requestTopUp() (the SAME service
 * API\WalletController::topUp() calls), which enforces every wallet.* limit
 * and creates one Razorpay order; the existing /webhooks/razorpay endpoint
 * credits the balance once Razorpay confirms capture. Nothing on this
 * screen credits a wallet directly.
 */
class Index extends Component
{
    public string $topUpAmount = '';

    public string $error = '';

    public string $notice = '';

    public function requestTopUp(WalletTopUpService $topUps, PaymentGateway $gateway): void
    {
        $this->reset('error', 'notice');

        $this->validate(
            ['topUpAmount' => ['required', 'numeric', 'min:1']],
            ['topUpAmount.min' => 'Enter an amount of at least 1.'],
        );

        if (! $gateway->isConfigured()) {
            $this->error = 'Wallet top-up needs an online payment gateway, which is not configured in this environment.';

            return;
        }

        $user = auth()->user();
        $scope = array_filter(['franchise_id' => $user->franchise_id, 'zone_id' => $user->zone_id]);

        try {
            $order = $topUps->requestTopUp($user, (float) $this->topUpAmount, $scope);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->dispatch('razorpay-open', order: $order);
        $this->notice = 'Complete the payment to add funds. Your balance updates once the payment is confirmed.';
    }

    public function render()
    {
        $user = auth()->user();
        $wallet = $user->wallet;

        $ledger = $wallet
            ? WalletTransaction::where('wallet_id', $wallet->id)->latest()->limit(30)->get()
            : collect();

        return view('livewire.customer.wallet.index', [
            'balance' => app(WalletService::class)->balance($user),
            'ledger' => $ledger,
            'currencySymbol' => \App\Models\Setting::get('locale.currency_symbol', '₹'),
            'gatewayConfigured' => app(PaymentGateway::class)->isConfigured(),
        ])->layout('components.layouts.customer', ['title' => 'Wallet']);
    }
}
