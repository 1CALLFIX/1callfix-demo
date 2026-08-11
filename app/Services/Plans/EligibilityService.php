<?php

namespace App\Services\Plans;

use App\Models\BusinessAccount;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * eligible_actor_type (coarse) + eligibility_rules json (fine-grained,
 * extensible without a migration — approved plan amendment 3). $actingAs is
 * passed explicitly by the caller rather than inferred from the actor's
 * class, since a User can be BOTH a customer and a provider — inference
 * would be ambiguous exactly where this needs to not be.
 */
class EligibilityService
{
    public function canPurchase(Model $actor, string $actingAs, Plan $plan): bool
    {
        if ($plan->eligible_actor_type !== $actingAs) {
            return false;
        }

        return $this->evaluateRules($actor, $actingAs, $plan->eligibility_rules ?? []);
    }

    private function evaluateRules(Model $actor, string $actingAs, array $rules): bool
    {
        foreach ($rules as $rule => $expected) {
            if (! $this->evaluateRule($actor, $actingAs, $rule, $expected)) {
                return false;
            }
        }

        return true;
    }

    private function evaluateRule(Model $actor, string $actingAs, string $rule, mixed $expected): bool
    {
        return match ($rule) {
            'requires_kyc_approved' => ! $expected || $this->kycApproved($actor, $actingAs),
            'min_jobs_completed' => $actingAs !== 'provider'
                || (($actor instanceof User ? $actor->providerProfile?->jobs_completed : 0) ?? 0) >= (int) $expected,
            'min_account_age_days' => ! $actor->created_at || $actor->created_at->diffInDays(now()) >= (int) $expected,
            'module_active' => true, // reserved for a future module-enabled-for-franchise check once modules other than Service exist
            // Unknown rule keys never block a purchase — extensible without breaking plans configured before the rule existed.
            default => true,
        };
    }

    private function kycApproved(Model $actor, string $actingAs): bool
    {
        if ($actingAs === 'provider' && $actor instanceof User) {
            return $actor->providerProfile?->kyc_status === 'approved';
        }
        if ($actingAs === 'business_account' && $actor instanceof BusinessAccount) {
            return $actor->kyc_status === 'approved';
        }

        return true;
    }
}
