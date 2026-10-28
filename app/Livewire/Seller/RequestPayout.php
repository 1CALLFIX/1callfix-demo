<?php

namespace App\Livewire\Seller;

use App\Livewire\Provider\RequestPayout as PartnerRequestPayout;
use App\Services\Kyc\KycWithdrawalPolicyService;
use App\Services\PayoutService;
use App\Services\WalletService;

/**
 * Seller payout request: the partner Request Payout screen as it is. A seller is a provider account
 * (Store::provider_id), so PayoutService::request('provider', ...) applies every rule unchanged — shop cash owed is
 * recovered from the wallet first and blocks the payout while any remains. Only the layout and tab links change.
 */
class RequestPayout extends PartnerRequestPayout
{
    public function render(WalletService $wallet, KycWithdrawalPolicyService $kycPolicy, PayoutService $payoutService)
    {
        return parent::render($wallet, $kycPolicy, $payoutService)
            ->with('area', 'seller')
            ->layout('components.layouts.seller', ['title' => 'Request Payout']);
    }
}
