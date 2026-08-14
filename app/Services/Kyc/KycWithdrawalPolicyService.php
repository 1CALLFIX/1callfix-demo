<?php

namespace App\Services\Kyc;

use App\Models\KycWithdrawalException;
use App\Models\Provider;

/**
 * Withdrawal eligibility is ALWAYS derived live from real conditions — never
 * a stored boolean (the mission's own explicit "do not use a simple
 * payments_enabled flag" rule). A Provider's earnings are never touched by
 * this service; it only answers "can this provider withdraw right now", and
 * why. Missed KYC never blocks login/jobs/earning — only withdrawal, and
 * only once genuinely overdue.
 */
class KycWithdrawalPolicyService
{
    public function isRestricted(Provider $provider, array $scope = []): bool
    {
        return $this->explain($provider, $scope)['restricted'];
    }

    /**
     * Full reasoning, for admin/support visibility — which single condition
     * (or combination) is actually driving the current restriction state,
     * rather than a bare true/false an admin has to reverse-engineer.
     */
    public function explain(Provider $provider, array $scope = []): array
    {
        if (! \App\Models\Setting::get('kyc.withdrawal_restriction_enabled', '1', $scope)) {
            return ['restricted' => false, 'reason' => 'withdrawal_restriction_disabled_platform_wide'];
        }

        if ($provider->kyc_status === 'approved') {
            return ['restricted' => false, 'reason' => 'kyc_approved'];
        }

        if (! $provider->kyc_deadline_at) {
            return ['restricted' => false, 'reason' => 'no_deadline_set'];
        }

        if (now()->lessThan($provider->kyc_deadline_at)) {
            return ['restricted' => false, 'reason' => 'within_deadline_window', 'deadline_at' => $provider->kyc_deadline_at];
        }

        $activeException = KycWithdrawalException::active()->where('provider_id', $provider->id)->latest('id')->first();
        if ($activeException) {
            return ['restricted' => false, 'reason' => 'active_exception_granted', 'exception_id' => $activeException->id, 'exception_expires_at' => $activeException->expires_at];
        }

        return ['restricted' => true, 'reason' => 'kyc_deadline_passed_no_exception', 'deadline_at' => $provider->kyc_deadline_at];
    }
}
