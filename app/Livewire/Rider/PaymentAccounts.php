<?php

namespace App\Livewire\Rider;

use App\Livewire\Provider\PaymentAccounts as PartnerPaymentAccounts;
use App\Models\PaymentAccount;
use Illuminate\Support\Facades\Auth;

/**
 * Rider bank / UPI accounts. The partner screen as it is: payment accounts belong to the user, not the provider,
 * so every rule (own accounts only, new accounts unverified until an admin verifies them) is the same. Only the
 * layout and the tab links change.
 */
class PaymentAccounts extends PartnerPaymentAccounts
{
    public function render()
    {
        return view('livewire.provider.payment-accounts', [
            'accounts' => PaymentAccount::where('user_id', Auth::id())->orderByDesc('is_default')->orderByDesc('id')->get(),
            'area' => 'rider',
        ])->layout('components.layouts.rider', ['title' => 'Payment Accounts']);
    }
}
