<?php

namespace App\Livewire\Customer\Earnings\Concerns;

use App\Models\User;
use App\Support\EarningsSettings;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — Stage 4. Shared gate for the three
 * customer Earnings tabs: the tab exists only while `earnings.enabled` AND
 * its own tab switch resolve ON for this customer's scope. Otherwise 404 —
 * a switched-off feature is not there at all, not "forbidden". Re-checked
 * on every request (render), so switching it off is effective immediately
 * even for an already-open page.
 */
trait EarningsTab
{
    abstract protected function tabSwitch(): string;

    protected function ensureTabOn(): void
    {
        abort_unless(EarningsSettings::customerTabOn($this->tabSwitch(), $this->earningsScope()), 404);
    }

    /** @return array<string, int> the customer's own scope hint for Setting::get() */
    protected function earningsScope(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return array_filter(['franchise_id' => $user->franchise_id, 'zone_id' => $user->zone_id]);
    }

    /** @return array<string, array{label: string, route: string}> the tabs currently on for this customer */
    protected function visibleTabs(): array
    {
        $scope = $this->earningsScope();

        return array_filter([
            'wallet' => ['label' => 'Wallet', 'route' => 'customer.earnings.wallet', 'switch' => 'earnings.wallet_tab'],
            'loyalty' => ['label' => 'Loyalty points', 'route' => 'customer.earnings.loyalty', 'switch' => 'earnings.loyalty_tab'],
            'referrals' => ['label' => 'Referrals', 'route' => 'customer.earnings.referrals', 'switch' => 'earnings.referral_tab'],
        ], fn ($t) => EarningsSettings::customerTabOn($t['switch'], $scope));
    }
}
