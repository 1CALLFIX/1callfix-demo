<?php

namespace App\Actions;

use App\Models\FieldWorker;
use Illuminate\Support\Facades\DB;

/** Mirrors ReviewProviderKycAction exactly, targeting FieldWorker/FieldWorkerDocument instead of Provider/ProviderDocument. */
class ReviewFieldWorkerKycAction
{
    public function approve(int $fieldWorkerId): FieldWorker
    {
        return DB::transaction(function () use ($fieldWorkerId) {
            $worker = FieldWorker::lockForUpdate()->findOrFail($fieldWorkerId);
            $worker->kyc_status = 'approved';
            $worker->save();

            $worker->documents()->where('status', 'pending')->update(['status' => 'approved']);

            return $worker->fresh();
        });
    }

    public function reject(int $fieldWorkerId, string $reason): FieldWorker
    {
        return DB::transaction(function () use ($fieldWorkerId, $reason) {
            $worker = FieldWorker::lockForUpdate()->findOrFail($fieldWorkerId);
            $worker->kyc_status = 'rejected';
            $worker->save();

            $worker->documents()->where('status', 'pending')->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
            ]);

            return $worker->fresh();
        });
    }
}
