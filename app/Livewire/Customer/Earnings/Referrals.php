<?php

namespace App\Livewire\Customer\Earnings;

use App\Livewire\Customer\Earnings\Concerns\EarningsTab;
use App\Models\Referral;
use App\Models\Setting;
use App\Support\EarningsSettings;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — Stage 4, Earnings → Referrals. Read-only:
 * the caller's own referral code and the referrals where THEY are the
 * referrer. Behind earnings.referral_tab (default off, D4 — referral capture
 * at signup is EARN4). Rules text is built from the live settings.
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

    public function render()
    {
        $this->ensureTabOn();

        $user = auth()->user();
        $scope = $this->earningsScope();
        $type = EarningsSettings::raw('referral.reward_type', $scope);

        return view('livewire.customer.earnings.referrals', [
            'tabs' => $this->visibleTabs(),
            'code' => $user->referral_code,
            'referrals' => Referral::query()->with('referred:id,name')
                ->where('referrer_id', $user->id)->latest('id')->paginate(20),
            'programOn' => EarningsSettings::on('referral.enabled', $scope),
            'rewardType' => $type,
            'rewardAmount' => EarningsSettings::number('referral.reward_amount', $scope),
            'rewardPoints' => EarningsSettings::integer('referral.reward_points', $scope),
            'maxPerCustomer' => EarningsSettings::integer('referral.max_per_customer', $scope),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.customer', ['title' => 'Referrals']);
    }
}
