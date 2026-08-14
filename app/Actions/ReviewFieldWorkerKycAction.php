<?php

namespace App\Actions;

use App\Models\FieldWorker;
use App\Notifications\KycNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\Kyc\KycDocumentService;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors ReviewProviderKycAction's document-completeness gate (documents
 * approved = required for approval) but WITHOUT the verification-video
 * requirement -- the mission's resolved decision names "Partner"
 * specifically for the video, never Rider/Worker.
 */
class ReviewFieldWorkerKycAction
{
    public function approve(int $fieldWorkerId): FieldWorker
    {
        return DB::transaction(function () use ($fieldWorkerId) {
            $worker = FieldWorker::lockForUpdate()->findOrFail($fieldWorkerId);

            $missing = app(KycDocumentService::class)->missingApprovedRequirements($worker, $worker->franchise?->country_id);
            if (! empty($missing)) {
                throw new \RuntimeException('Cannot approve KYC — the following required documents are not yet approved: '.implode(', ', $missing));
            }

            $worker->kyc_status = 'approved';
            $worker->save();

            $worker->documents()->where('is_current', true)->where('status', 'pending')->update(['status' => 'approved']);

            if ($worker->user) {
                $worker->user->notify(new KycNotification('kyc_approved', ChannelResolver::resolve(['franchise_id' => $worker->franchise_id])));
            }

            return $worker->fresh();
        });
    }

    public function reject(int $fieldWorkerId, string $reason): FieldWorker
    {
        return DB::transaction(function () use ($fieldWorkerId, $reason) {
            $worker = FieldWorker::lockForUpdate()->findOrFail($fieldWorkerId);
            $worker->kyc_status = 'rejected';
            $worker->save();

            $worker->documents()->where('is_current', true)->where('status', 'pending')->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
            ]);

            if ($worker->user) {
                $worker->user->notify(new KycNotification('kyc_rejected', ChannelResolver::resolve(['franchise_id' => $worker->franchise_id]), ['reason' => $reason]));
            }

            return $worker->fresh();
        });
    }
}
