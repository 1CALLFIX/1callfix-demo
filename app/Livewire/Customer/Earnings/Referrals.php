<?php

namespace App\Livewire\Customer\Earnings;

use App\Livewire\Customer\Earnings\Concerns\EarningsTab;
use App\Models\Setting;
use Livewire\Component;
use Livewire\WithPagination;
use App\Services\Referrals\ReferralPresenter;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — Stage 4, Earnings → Referrals. Read-only:
 * the caller's own referral code and the referrals where THEY are the
 * referrer. Behind earnings.referral_tab AND referral.enabled (EARN4).
 * Everything shown comes from ReferralPresenter — the same data
 * GET /api/referrals/mine returns — including the share link and the
 * plain-language status labels.
 */
class Referrals extends Component
{
    use EarningsTab;
    use WithPagination;

    protected function tabSwitch(): string
    {
        return 'earnings.referral_tab';
    }

    public function mount(): void
    {
        $this->ensureTabOn();
    }

    public function render(ReferralPresenter $presenter)
    {
        $this->ensureTabOn();

        $user = auth()->user();

        return view('livewire.customer.earnings.referrals', [
            'tabs' => $this->visibleTabs(),
            'code' => $user->referral_code,
            'shareLink' => $presenter->shareLink($user),
            'referrals' => $presenter->paginate($user),
            'rules' => $presenter->rules($user),
            'referralBalance' => app(\App\Services\ReferralBalanceService::class)->available($user),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
            'platformName' => Setting::get('branding.platform_name', '1CallFix'),
        ])->layout('components.layouts.customer', ['title' => 'Referrals']);
    }
}
