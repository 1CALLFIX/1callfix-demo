<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingCompensation;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tips + waiting/rain/overtime/peak/night compensation (mission Phase 5).
 * Every payout goes through WalletService::credit()/debit() -- the ONE
 * financial ledger, never a direct balance edit. Every rate is a
 * Setting, defaulted to 0 so nothing pays out until an admin configures a
 * real rate (mission's own explicit instruction). Idempotent per
 * (booking, type) via the unique DB constraint AND an existence check
 * before crediting, same double-guard pattern as
 * LoyaltyService::earn()/CommissionService::applyForBooking().
 *
 * overtime/night/peak are auto-computed from REAL booking timestamps —
 * nothing invented. waiting/rain have NO real data source in this schema
 * (no arrival-time capture, no weather integration) — they are
 * ADMIN-TRIGGERED only, via applyManual(), never auto-derived from
 * unrelated data. See KNOWN_RISKS_AND_DECISIONS.md.
 */
class CompensationService
{
    public function __construct(private WalletService $walletService)
    {
    }

    /** Called once, right after a booking completes — see CompleteBookingAction. Silently no-ops per type when its rate is unconfigured (0) or the booking doesn't qualify. */
    public function applyAutomaticForBooking(Booking $booking, array $scope = []): array
    {
        if (! $booking->provider || ! $booking->provider->user) {
            return [];
        }

        $applied = [];

        if ($c = $this->applyOvertime($booking, $scope)) {
            $applied[] = $c;
        }
        if ($c = $this->applyTimeWindow($booking, 'night', 'compensation.night_window_start_hour', 'compensation.night_window_end_hour', 'compensation.night_flat_amount', $scope)) {
            $applied[] = $c;
        }
        if ($c = $this->applyTimeWindow($booking, 'peak', 'compensation.peak_window_start_hour', 'compensation.peak_window_end_hour', 'compensation.peak_flat_amount', $scope)) {
            $applied[] = $c;
        }

        return $applied;
    }

    /**
     * Overtime = actual duration (completed_at - scheduled_at) exceeding
     * the service's own duration_estimate_mins by more than the configured
     * threshold, paid per extra minute at the configured rate. Real,
     * already-existing columns — no invented signal.
     */
    private function applyOvertime(Booking $booking, array $scope): ?BookingCompensation
    {
        $ratePerMinute = (float) Setting::get('compensation.overtime_rate_per_minute', '0', $scope);
        if ($ratePerMinute <= 0 || ! $booking->scheduled_at || ! $booking->completed_at || ! $booking->service) {
            return null;
        }

        $thresholdMinutes = (int) Setting::get('compensation.overtime_threshold_minutes', '0', $scope);
        $actualMinutes = $booking->scheduled_at->diffInMinutes($booking->completed_at);
        $estimateMinutes = (int) $booking->service->duration_estimate_mins;
        $overtimeMinutes = $actualMinutes - $estimateMinutes - $thresholdMinutes;

        if ($overtimeMinutes <= 0) {
            return null;
        }

        $amount = round($overtimeMinutes * $ratePerMinute, 2);

        return $this->credit($booking, 'overtime', $amount, [
            'actual_minutes' => $actualMinutes, 'estimate_minutes' => $estimateMinutes,
            'threshold_minutes' => $thresholdMinutes, 'overtime_minutes' => $overtimeMinutes, 'rate_per_minute' => $ratePerMinute,
        ]);
    }

    /** Night/peak: completed_at's hour falls within an admin-configured window, pays a flat configured amount. Handles a window that wraps midnight (e.g. 22-6). */
    private function applyTimeWindow(Booking $booking, string $type, string $startKey, string $endKey, string $amountKey, array $scope): ?BookingCompensation
    {
        $amount = (float) Setting::get($amountKey, '0', $scope);
        if ($amount <= 0 || ! $booking->completed_at) {
            return null;
        }

        $start = (int) Setting::get($startKey, '-1', $scope);
        $end = (int) Setting::get($endKey, '-1', $scope);
        if ($start < 0 || $end < 0 || $start > 23 || $end > 23) {
            return null; // window not configured — never guess a default window
        }

        $hour = (int) $booking->completed_at->format('G');
        $inWindow = $start <= $end ? ($hour >= $start && $hour < $end) : ($hour >= $start || $hour < $end);

        if (! $inWindow) {
            return null;
        }

        return $this->credit($booking, $type, $amount, ['completed_hour' => $hour, 'window_start_hour' => $start, 'window_end_hour' => $end]);
    }

    /**
     * Rain/waiting have no automatic data source — an admin applies them
     * directly with real evidence (a support ticket, a franchise report).
     * $minutes is only meaningful for 'waiting' (multiplied by the
     * configured per-minute rate); 'rain' pays the flat configured amount
     * regardless of $minutes.
     */
    public function applyManual(Booking $booking, string $type, User $admin, ?int $minutes = null, array $scope = []): BookingCompensation
    {
        if (! in_array($type, ['rain', 'waiting'], true)) {
            throw new \InvalidArgumentException("applyManual() only supports 'rain'/'waiting' — {$type} is auto-computed.");
        }

        if (! $booking->provider || ! $booking->provider->user) {
            throw new \RuntimeException('This booking has no assigned provider to compensate.');
        }

        if ($type === 'rain') {
            $amount = (float) Setting::get('compensation.rain_flat_amount', '0', $scope);
            $basis = ['flat_amount' => $amount];
        } else {
            $rate = (float) Setting::get('compensation.waiting_rate_per_minute', '0', $scope);
            $amount = round((float) ($minutes ?? 0) * $rate, 2);
            $basis = ['minutes' => $minutes, 'rate_per_minute' => $rate];
        }

        if ($amount <= 0) {
            throw new \RuntimeException("Compensation rate for '{$type}' is not configured (0) — set it in Settings first.");
        }

        $compensation = $this->credit($booking, $type, $amount, $basis, $admin);

        if (! $compensation) {
            throw new \RuntimeException("This booking already has a '{$type}' compensation applied.");
        }

        return $compensation;
    }

    private function credit(Booking $booking, string $type, float $amount, array $basis, ?User $admin = null): ?BookingCompensation
    {
        if (BookingCompensation::where('booking_id', $booking->id)->where('type', $type)->exists()) {
            return null; // idempotent — never double-pay the same booking+type
        }

        return DB::transaction(function () use ($booking, $type, $amount, $basis, $admin) {
            $ref = 'compensation:'.$booking->id.':'.$type.':'.Str::uuid();

            $this->walletService->credit($booking->provider->user, $amount, ucfirst($type)." compensation for booking {$booking->code}", $ref);

            return BookingCompensation::create([
                'booking_id' => $booking->id,
                'provider_id' => $booking->provider_id,
                'type' => $type,
                'amount' => $amount,
                'computed_basis' => $basis,
                'status' => 'applied',
                'applied_by' => $admin?->id,
                'wallet_transaction_ref' => $ref,
            ]);
        });
    }
}
