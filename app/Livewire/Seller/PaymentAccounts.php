<?php

namespace App\Livewire\Seller;

use App\Livewire\Provider\PaymentAccounts as PartnerPaymentAccounts;
use App\Models\PaymentAccount;
use Illuminate\Support\Facades\Auth;

/** Seller bank / UPI accounts: the partner screen as it is (accounts belong to the user); only layout and tabs change. */
class PaymentAccounts extends PartnerPaymentAccounts
{
    public function render()
    {
        return view('livewire.provider.payment-accounts', [
            'accounts' => PaymentAccount::where('user_id', Auth::id())->orderByDesc('is_default')->orderByDesc('id')->get(),
            'area' => 'seller',
        ])->layout('components.layouts.seller', ['title' => 'Payment Accounts']);
    }
}
