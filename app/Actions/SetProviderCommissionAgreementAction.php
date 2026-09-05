<?php

namespace App\Actions;

use App\Models\Provider;
use App\Models\ProviderCommissionAgreement;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of provider_commission_agreements — tier 1 of
 * ProviderCommercialRateResolver's hierarchy. One row per provider
 * (upsert on provider_id), same "single current state, audited via
 * ActivityLogger rather than a history table" shape as
 * SetProviderOnlineStatusAction.
 */
class SetProviderCommissionAgreementAction
{
    public function set(Provider $provider, float $percent, ?string $notes, User $setBy): ProviderCommissionAgreement
    {
        if ($percent < 0 || $percent > 100) {
            throw ValidationException::withMessages(['percent' => 'Platform fee must be between 0 and 100.']);
        }

        $agreement = ProviderCommissionAgreement::updateOrCreate(
            ['provider_id' => $provider->id],
            ['platform_fee_percent' => $percent, 'notes' => $notes, 'set_by_user_id' => $setBy->id]
        );

        ActivityLogger::logModel(
            $setBy,
            $provider,
            "Negotiated commercial rate set to {$percent}% for provider {$provider->id}",
            ['platform_fee_percent' => $percent, 'notes' => $notes]
        );

        return $agreement;
    }

    /** Reverts the provider to the franchise/global fallback chain. */
    public function clear(Provider $provider, User $clearedBy): void
    {
        $existing = ProviderCommissionAgreement::where('provider_id', $provider->id)->first();

        if (! $existing) {
            return;
        }

        $existing->delete();

        ActivityLogger::logModel(
            $clearedBy,
            $provider,
            "Negotiated commercial rate cleared for provider {$provider->id} — now inheriting franchise/global default",
        );
    }
}
