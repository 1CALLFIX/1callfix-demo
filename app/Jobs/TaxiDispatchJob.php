<?php

namespace App\Jobs;

use App\Events\TaxiRideStatusUpdated;
use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\Setting;
use App\Models\TaxiRide;
use App\Services\DispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 22.6 (Taxi) — a close structural mirror of ParcelDispatchJob
 * (itself a mirror of ServiceMatchingJob). Same deliberate non-generalization
 * reasoning as ParcelDispatchJob's own docblock: similar SHAPE, structurally
 * different models/events/Settings throughout.
 */
class TaxiDispatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private function batchSize(): int
    {
        return (int) Setting::get('taxi.dispatch.offer_batch_size', 5);
    }

    private function offerTimeoutSeconds(): int
    {
        return (int) Setting::get('taxi.dispatch.offer_timeout_seconds', 20);
    }

    private function maxRounds(): int
    {
        return (int) Setting::get('taxi.dispatch.max_rounds', 6);
    }

    public function __construct(
        public int $taxiRideId,
        public int $round = 1,
    ) {
    }

    public function handle(DispatchService $dispatchService): void
    {
        $result = DB::transaction(function () {
            $ride = TaxiRide::lockForUpdate()->find($this->taxiRideId);

            if (!$ride || $ride->status !== 'searching_driver' && $ride->status !== 'requested') {
                return null;
            }

            $justStartedSearching = false;

            if ($ride->status === 'requested') {
                $ride->status = 'searching_driver';
                $ride->save();

                $ride->statusHistory()->create([
                    'status' => 'searching_driver',
                    'note' => 'Dispatch started — searching for an eligible driver',
                    'changed_at' => now(),
                ]);

                $justStartedSearching = true;
            }

            return [$ride->fresh(), $justStartedSearching];
        });

        [$ride, $justStartedSearching] = $result ?? [null, false];

        if (!$ride) {
            return;
        }

        if ($justStartedSearching) {
            event(new TaxiRideStatusUpdated($ride));
        }

        $this->timeoutExpiredAttempts($ride);

        $maxRounds = $this->maxRounds();

        if ($this->round > $maxRounds) {
            Log::warning("TaxiDispatchJob: exhausted {$maxRounds} rounds for taxi ride [{$ride->id}] with no acceptance — leaving for manual admin assignment.");
            return;
        }

        $offerTimeoutSeconds = $this->offerTimeoutSeconds();
        $candidates = $dispatchService->findTaxiDriverCandidates($ride, $this->batchSize());

        if ($candidates->isEmpty()) {
            self::dispatch($this->taxiRideId, $this->round + 1)
                ->delay(now()->addSeconds($offerTimeoutSeconds));
            return;
        }

        foreach ($candidates as $candidate) {
            DispatchAttempt::create([
                'dispatchable_type' => TaxiRide::class,
                'dispatchable_id' => $ride->id,
                'notifiable_type' => FieldWorker::class,
                'notifiable_id' => $candidate['provider']->id,
                'status' => 'notified',
                'distance_km' => $candidate['distance_km'],
                'notified_at' => now(),
            ]);
        }

        self::dispatch($this->taxiRideId, $this->round + 1)
            ->delay(now()->addSeconds($offerTimeoutSeconds));
    }

    private function timeoutExpiredAttempts(TaxiRide $ride): void
    {
        DispatchAttempt::where('dispatchable_type', TaxiRide::class)
            ->where('dispatchable_id', $ride->id)
            ->where('status', 'notified')
            ->where('notified_at', '<=', now()->subSeconds($this->offerTimeoutSeconds()))
            ->update(['status' => 'timeout', 'responded_at' => now()]);
    }
}
