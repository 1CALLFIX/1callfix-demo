<?php

namespace App\Actions;

use App\Models\Provider;
use Illuminate\Support\Facades\DB;

class ReviewProviderKycAction
{
    public function approve(int $providerId): Provider
    {
        return DB::transaction(function () use ($providerId) {
            $provider = Provider::lockForUpdate()->findOrFail($providerId);
            $provider->kyc_status = 'approved';
            $provider->save();

            $provider->documents()->where('status', 'pending')->update(['status' => 'approved']);

            return $provider->fresh();
        });
    }

    public function reject(int $providerId, string $reason): Provider
    {
        return DB::transaction(function () use ($providerId, $reason) {
            $provider = Provider::lockForUpdate()->findOrFail($providerId);
            $provider->kyc_status = 'rejected';
            $provider->save();

            $provider->documents()->where('status', 'pending')->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
            ]);

            return $provider->fresh();
        });
    }
}
