<?php

namespace App\Support;

use App\Models\User;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — the ONE "Earnings" nav entry (header,
 * bottom nav, account page). Points at the first tab that is switched on
 * for this customer; null (entry hidden) when the master switch or every tab
 * is off.
 */
final class CustomerEarningsNav
{
    private const TABS = [
        'earnings.wallet_tab' => 'customer.earnings.wallet',
        'earnings.loyalty_tab' => 'customer.earnings.loyalty',
        'earnings.referral_tab' => 'customer.earnings.referrals',
    ];

    public static function firstRoute(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $scope = array_filter(['franchise_id' => $user->franchise_id, 'zone_id' => $user->zone_id]);

        foreach (self::TABS as $switch => $route) {
            if (EarningsSettings::customerTabOn($switch, $scope)) {
                return $route;
            }
        }

        return null;
    }
}
