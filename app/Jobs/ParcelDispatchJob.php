<?php

namespace App\Jobs;

use App\Events\ParcelOrderStatusUpdated;
use App\Models\DispatchAttempt;
use App\Models\ParcelOrder;
use App\Models\Setting;
use App\Services\DispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 22.4 (Parcel) — a close structural mirror of ServiceMatchingJob
 * (self-requeuing, round-limited, row-locked pending->searching transition,
 * same "leave for manual admin assignment after max rounds" fallback), but
 * calling DispatchService::findWorkerCandidates() and writing
 * dispatch_attempts rows via the ORDER-side polymorphic columns
 * (dispatchable_type/id) + WORKER-side polymorphic columns
 * (notifiable_type/id) instead of booking_id/provider_id.
 *
 * Deliberately NOT a generalized "OrderMatchingJob" — the two jobs' bodies
 * are similar in SHAPE (same race-safety pattern, same round/timeout
 * logic) but operate on structurally different models/events/settings
 * namespaces throughout; forcing one shared job class here would mean
 * either a large parameter surface or runtime type-checking scattered
 * through the body, exactly the "generalize because names are similar"
 * trap the mission's own instructions warn against. ServiceMatchingJob is
 * completely untouched by this phase.
 */
class ParcelDispatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private function batchSize(): int
    {
        return (int) Setting::get('parcel.dispatch.offer_batch_size', 5);
    }

    private function offerTimeoutSeconds(): int
    {
        return (int) Setting::get('parcel.dispatch.offer_timeout_seconds', 25);
    }

    private function maxRounds(): int
    {
        return (int) Setting::get('parcel.dispatch.max_rounds', 6);
    }

    public function __construct(
        public int $parcelOrderId,
        public int $round = 1,
    ) {
    }

    public function handle(DispatchService $dispatchService): void
    {
        $result = DB::transaction(function () {
            $order = ParcelOrder::lockForUpdate()->find($this->parcelOrderId);

            if (!$order || $order->status !== 'searching_worker' && $order->status !== 'pending') {
                return null;
            }

            $justStartedSearching = false;

            if ($order->status === 'pending') {
                $order->status = 'searching_worker';
                $order->save();

                $order->statusHistory()->create([
                    'status' => 'searching_worker',
                    'note' => 'Dispatch started — searching for an eligible rider',
                    'changed_at' => now(),
                ]);

                $justStartedSearching = true;
            }

            return [$order->fresh(), $justStartedSearching];
        });

        [$order, $justStartedSearching] = $result ?? [null, false];

        if (!$order) {
            return;
        }

        if ($justStartedSearching) {
            event(new ParcelOrderStatusUpdated($order));
        }

        $this->timeoutExpiredAttempts($order);

        $maxRounds = $this->maxRounds();

        if ($this->round > $maxRounds) {
            Log::warning("ParcelDispatchJob: exhausted {$maxRounds} rounds for parcel order [{$order->id}] with no acceptance — leaving for manual admin assignment.");
            return;
        }

        $offerTimeoutSeconds = $this->offerTimeoutSeconds();
        $candidates = $dispatchService->findWorkerCandidates($order, $this->batchSize());

        if ($candidates->isEmpty()) {
            self::dispatch($this->parcelOrderId, $this->round + 1)
                ->delay(now()->addSeconds($offerTimeoutSeconds));
            return;
        }

        foreach ($candidates as $candidate) {
            DispatchAttempt::create([
                'dispatchable_type' => ParcelOrder::class,
                'dispatchable_id' => $order->id,
                'notifiable_type' => \App\Models\FieldWorker::class,
                'notifiable_id' => $candidate['provider']->id,
                'status' => 'notified',
                'distance_km' => $candidate['distance_km'],
                'notified_at' => now(),
            ]);

            // No NewJobOffered-equivalent broadcast event exists yet for
            // Parcel -- ParcelOrderStatusUpdated (fired on real status
            // transitions) is the only broadcast this phase adds; a
            // per-offer broadcast (NewJobOffered's own real counterpart)
            // is a real, scoped-out enhancement for whenever a Rider app
            // actually consumes it, not invented speculatively here.
        }

        self::dispatch($this->parcelOrderId, $this->round + 1)
            ->delay(now()->addSeconds($offerTimeoutSeconds));
    }

    private function timeoutExpiredAttempts(ParcelOrder $order): void
    {
        DispatchAttempt::where('dispatchable_type', ParcelOrder::class)
            ->where('dispatchable_id', $order->id)
            ->where('status', 'notified')
            ->where('notified_at', '<=', now()->subSeconds($this->offerTimeoutSeconds()))
            ->update(['status' => 'timeout', 'responded_at' => now()]);
    }
}
