<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Provider;
use App\Models\ProviderReliabilityEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * REF 1CF-CANCEL-POLICY-001 — records a reliability penalty and lowers `providers.reliability_score` (floor 0).
 * One penalty per booking per type (DB-unique), so a retried sweep can never double-penalise.
 * NOTE: the score is recorded and shown to operators; dispatch ranking does not read it yet.
 */
class ProviderReliabilityService
{
    public function penalise(Provider $provider, ?Booking $booking, string $type, int $points, ?string $note = null): bool
    {
        try {
            return DB::transaction(function () use ($provider, $booking, $type, $points, $note) {
                ProviderReliabilityEvent::create([
                    'provider_id' => $provider->id,
                    'booking_id' => $booking?->id,
                    'type' => $type,
                    'points' => -abs($points),
                    'note' => $note,
                ]);

                $locked = Provider::lockForUpdate()->findOrFail($provider->id);
                $locked->reliability_score = max(0, (int) $locked->reliability_score - abs($points));
                $locked->save();

                return true;
            });
        } catch (QueryException $e) {
            // unique(booking_id, type): already penalised for this job.
            if (str_contains(strtolower($e->getMessage()), 'unique') || str_contains(strtolower($e->getMessage()), 'duplicate')) {
                return false;
            }

            throw $e;
        }
    }
}
