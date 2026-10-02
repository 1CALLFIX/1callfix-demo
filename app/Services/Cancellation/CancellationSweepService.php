<?php

namespace App\Services\Cancellation;

use App\Actions\CustomerCancelBookingAction;
use App\Actions\RespondToExtraWorkAction;
use App\Models\Booking;
use App\Models\BookingCancellationRequest;
use App\Models\BookingExtraItem;
use App\Models\Commission;
use App\Models\Setting;
use App\Notifications\ProviderJobStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\AdminOpsAlertService;
use App\Services\ProviderReliabilityService;
use App\Support\Journey\StageNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * REF 1CF-CANCEL-POLICY-001 — the hourly housekeeping behind the cancellation policy. Every step is idempotent:
 *   - spares-delay notices to BOTH sides (warning N days before the limit, unlocked, expected date passed,
 *     spares-ready-but-not-resumed + reliability penalty);
 *   - extra-work requests unanswered after 72h are declined and the job resumes at the original price;
 *   - cancellation charges unpaid after 7 days are flagged for admin review (never left stuck);
 *   - retries the provider payout of a customer-cancelled job whose first attempt failed.
 */
class CancellationSweepService
{
    public const DEFAULT_WARNING_DAYS_BEFORE = 3;
    public const DEFAULT_EXTRA_WORK_TIMEOUT_HOURS = 72;
    public const RELIABILITY_PENALTY = 10;

    public function __construct(
        private SparesDelayClock $clock,
        private ProviderReliabilityService $reliability,
        private CustomerCancelBookingAction $customerCancel,
    ) {
    }

    /** @return array<string,int> */
    public function run(): array
    {
        return [
            'spares_notices' => $this->sparesNotices(),
            'extra_work_declined' => $this->extraWorkTimeouts(),
            'unpaid_flagged' => $this->unpaidRequests(),
            'payouts_retried' => $this->payoutRetries(),
        ];
    }

    public function sparesNotices(): int
    {
        $sent = 0;

        Booking::query()->where('status', 'on_hold')->where('hold_reason', 'awaiting_spares')
            ->with(['customer', 'provider.user', 'service'])
            ->chunkById(100, function ($bookings) use (&$sent) {
                foreach ($bookings as $booking) {
                    if ($this->clock->customerSupplied($booking)) {
                        continue; // the customer's own part: never counts, never nags
                    }

                    $counted = $this->clock->countedSeconds($booking);
                    $threshold = $this->clock->thresholdDays($booking) * 86400;
                    $warnBefore = max(0, (int) PolicySettings::current('cancellation.spares_warning_days_before', $this->clock->scopeFor($booking)));

                    if ($counted >= $threshold) {
                        $sent += $this->notice($booking, 'unlock', 'spares_cancel_unlocked');
                    } elseif ($warnBefore > 0 && $counted >= $threshold - $warnBefore * 86400) {
                        $sent += $this->notice($booking, 'warn', 'spares_delay_warning');
                    }

                    if ($booking->spares_expected_at && $booking->spares_expected_at->lt(now()->startOfDay())) {
                        $sent += $this->notice($booking, 'expected_passed:'.$booking->spares_expected_at->toDateString(), 'spares_date_passed');
                    }

                    if ($this->clock->providerResumeOverdue($booking)) {
                        if ($this->notice($booking, 'resume_overdue', 'spares_resume_overdue')) {
                            $sent++;
                            if ($booking->provider) {
                                $this->reliability->penalise($booking->provider, $booking, 'spares_not_resumed', max(0, (int) PolicySettings::current('cancellation.reliability_penalty_points')), 'Spares ready but the job was not resumed in time');
                            }
                        }
                    }
                }
            });

        return $sent;
    }

    public function extraWorkTimeouts(): int
    {
        $hours = max(1, (int) PolicySettings::current('booking.extra_work_timeout_hours'));
        $declined = 0;

        BookingExtraItem::query()
            ->where('status', 'pending_approval')
            ->where('created_at', '<=', now()->subHours($hours))
            ->with('booking')
            ->get()
            ->each(function (BookingExtraItem $item) use (&$declined) {
                $booking = $item->booking;
                if (! $booking || $booking->status !== 'on_hold' || $booking->hold_reason !== 'awaiting_customer_approval') {
                    return;
                }

                try {
                    app(RespondToExtraWorkAction::class)->execute($item->id, $booking->customer_id, false);
                    StageNotifier::customer($booking->fresh(), 'extra_work_expired');
                    $declined++;
                } catch (\RuntimeException $e) {
                    // answered or resumed in the meantime — nothing to do
                }
            });

        return $declined;
    }

    public function unpaidRequests(): int
    {
        $flagged = 0;

        BookingCancellationRequest::query()
            ->where('status', 'awaiting_payment')
            ->where('due_by', '<=', now())
            ->with('booking')
            ->get()
            ->each(function (BookingCancellationRequest $request) use (&$flagged) {
                $request->update(['status' => 'awaiting_admin', 'flagged_at' => now()]);
                $flagged++;

                try {
                    app(AdminOpsAlertService::class)->cancellationEvent('cancel_charge_unpaid', $request->booking);
                } catch (\Throwable $e) {
                    Log::warning('Unpaid cancellation alert failed: '.$e->getMessage());
                }
            });

        return $flagged;
    }

    public function payoutRetries(): int
    {
        $retried = 0;

        Booking::query()
            ->where('status', 'cancelled')->whereIn('cancelled_by_role', ['customer', 'provider'])
            ->where('cancellation_fee', '>', 0)->whereNotNull('provider_id')
            ->where('updated_at', '>=', now()->subDays(30))
            ->whereNotIn('id', Commission::query()->select('booking_id'))
            ->get()
            ->each(function (Booking $booking) use (&$retried) {
                $this->customerCancel->payProvider($booking, (float) $booking->cancellation_fee);
                $retried++;
            });

        return $retried;
    }

    /** Send once per (booking, key): the claim is taken under the row lock, so overlapping runs cannot double-send. */
    private function notice(Booking $booking, string $key, string $event): int
    {
        $claimed = DB::transaction(function () use ($booking, $key) {
            $locked = Booking::lockForUpdate()->find($booking->id);
            $notices = $locked->spares_notices ?? [];
            if (isset($notices[$key])) {
                return false;
            }
            $notices[$key] = now()->toIso8601String();
            $locked->spares_notices = $notices;
            $locked->save();

            return true;
        });

        if (! $claimed) {
            return 0;
        }

        StageNotifier::customer($booking, $event);

        try {
            if ($booking->provider?->user) {
                $channels = ChannelResolver::resolve(array_filter(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]));
                $booking->provider->user->notify(new ProviderJobStatusNotification($event, $booking, $channels));
            }
        } catch (\Throwable $e) {
            Log::error("Provider notice [{$event}] failed for booking [{$booking->id}]: ".$e->getMessage());
        }

        return 1;
    }
}
