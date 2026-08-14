<?php

namespace App\Services\Documents;

use App\Models\Country;
use App\Models\DocumentNumberSequence;
use App\Models\GeneratedDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent, concurrency-safe document numbering. The SAME documentable
 * (e.g. a specific Payment) always gets the SAME number back on every call
 * — a second admin downloading the same invoice five minutes later gets
 * the identical number, never a fresh one (checked first, before touching
 * the sequence at all). Sequence increments are row-locked
 * (DB::transaction()+lockForUpdate()), same convention as every other
 * sequential/financial counter in this codebase (WalletService,
 * FlashSaleService::redeem(), PerformanceCampaignService::disburse()).
 */
class DocumentNumberService
{
    public function numberFor(Model $documentable, string $type, ?Country $country = null, ?User $generatedBy = null): GeneratedDocument
    {
        $existing = GeneratedDocument::where('documentable_type', get_class($documentable))
            ->where('documentable_id', $documentable->getKey())
            ->where('type', $type)
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($documentable, $type, $country, $generatedBy) {
            $year = (int) now()->format('Y');

            $sequence = DocumentNumberSequence::lockForUpdate()
                ->firstOrCreate(
                    ['type' => $type, 'country_id' => $country?->id, 'year' => $year],
                    ['last_number' => 0]
                );

            $sequence->increment('last_number');

            $number = $this->format($type, $country, $year, $sequence->last_number);

            // A second concurrent caller that lost the lockForUpdate race
            // above would try to insert the identical documentable+type row
            // again — the table's own unique constraint makes that the
            // final, authoritative idempotency guard, not just the
            // existence check at the top of this method.
            return GeneratedDocument::firstOrCreate(
                ['documentable_type' => get_class($documentable), 'documentable_id' => $documentable->getKey(), 'type' => $type],
                ['number' => $number, 'country_id' => $country?->id, 'generated_by' => $generatedBy?->id, 'created_at' => now()]
            );
        });
    }

    private function format(string $type, ?Country $country, int $year, int $sequenceNumber): string
    {
        $prefix = strtoupper(substr($type, 0, 3)); // INV / REC
        $countryCode = $country?->code ?? 'XX';

        return sprintf('%s/%s/%d/%06d', $prefix, $countryCode, $year, $sequenceNumber);
    }
}
