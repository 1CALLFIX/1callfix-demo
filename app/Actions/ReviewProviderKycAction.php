<?php

namespace App\Actions;

use App\Models\Provider;
use App\Notifications\KycNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\Kyc\KycDocumentService;
use Illuminate\Support\Facades\DB;

/**
 * approve() now enforces the mission's own gate: DOCUMENTS VERIFIED + VIDEO
 * VERIFIED (+ other checks, none invented) = KYC APPROVED. A verification
 * video is only required if the provider actually has one submitted or
 * under review already, OR the platform-wide 'kyc.require_verification_video'
 * policy is on (default on, per the mission's own resolved decision that a
 * video is required for Partner KYC) -- approve() throws with a specific,
 * actionable reason rather than silently downgrading the check.
 */
class ReviewProviderKycAction
{
    public function approve(int $providerId): Provider
    {
        return DB::transaction(function () use ($providerId) {
            $provider = Provider::lockForUpdate()->findOrFail($providerId);

            $this->assertReadyForApproval($provider);

            $provider->kyc_status = 'approved';
            $provider->save();

            $provider->documents()->where('is_current', true)->where('status', 'pending')->update(['status' => 'approved']);

            if ($provider->user) {
                $provider->user->notify(new KycNotification('kyc_approved', ChannelResolver::resolve(['franchise_id' => $provider->franchise_id])));
            }

            return $provider->fresh();
        });
    }

    public function reject(int $providerId, string $reason): Provider
    {
        return DB::transaction(function () use ($providerId, $reason) {
            $provider = Provider::lockForUpdate()->findOrFail($providerId);
            $provider->kyc_status = 'rejected';
            $provider->save();

            $provider->documents()->where('is_current', true)->where('status', 'pending')->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
            ]);

            if ($provider->user) {
                $provider->user->notify(new KycNotification('kyc_rejected', ChannelResolver::resolve(['franchise_id' => $provider->franchise_id]), ['reason' => $reason]));
            }

            return $provider->fresh();
        });
    }

    private function assertReadyForApproval(Provider $provider): void
    {
        $missing = app(KycDocumentService::class)->missingApprovedRequirements($provider, $provider->franchise?->country_id);

        if (! empty($missing)) {
            throw new \RuntimeException('Cannot approve KYC — the following required documents are not yet approved: '.implode(', ', $missing));
        }

        $videoRequired = (bool) \App\Models\Setting::get('kyc.require_verification_video', '1', array_filter(['franchise_id' => $provider->franchise_id]));

        if ($videoRequired && $provider->kyc_video_status !== 'approved') {
            throw new \RuntimeException('Cannot approve KYC — the verification video has not been approved yet.');
        }
    }
}
