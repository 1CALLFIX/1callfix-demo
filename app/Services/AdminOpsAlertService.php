<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\AdminOpsAlertNotification;
use App\Notifications\Channels\PushChannel;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\Log;

/**
 * Fans a single operational event out to every admin who opted in to
 * order alerts (users.push_ops_alerts = true AND has an fcm_token). One
 * place so the two hook sites — booking creation and Razorpay capture —
 * stay one line each and can't drift.
 *
 * Best-effort: a failure here must never roll back a booking or a
 * payment-capture webhook, so every send is guarded.
 */
class AdminOpsAlertService
{
    public function bookingCreated(Booking $booking): void
    {
        $this->fanOut('booking_created', $booking);
    }

    public function paymentCaptured(Payment $payment): void
    {
        $this->fanOut('payment_captured', $payment);
    }

    /** RazorpayWebhookHandler: the gateway reported a captured amount that differs from the Payment row. */
    public function paymentAmountMismatch(Payment $payment): void
    {
        $this->fanOut('payment_amount_mismatch', $payment);
    }

    /**
     * REF 1CF-IMPLEMENT-20260922-L01 — the T+5 dispatch-escalation alert
     * (DispatchDeadlineSweepService). Deliberately NOT routed through the
     * unscoped fanOut() above (finding C-03: that method targets every
     * admin with push_ops_alerts=true platform-wide, no geography check at
     * all) — a franchise-scoped admin in Mumbai has no business being
     * paged about a stuck booking in Chennai. Scoped per-admin against
     * their real role_assignments via AuthorizationService::can(), the
     * same additive/fail-safe coverage semantics scopeQuery() already
     * documents (super_admin and any 'global' grant see everything; a
     * covering franchise/zone/city/country grant sees its own; zero
     * covering grants sees nothing) — just applied user-by-user rather
     * than as a row filter, because the admin (not the booking) is the
     * subject being tested here.
     */
    public function dispatchEscalation(Booking $booking, bool $jobFailed = false): void
    {
        $this->fanOutScoped($jobFailed ? 'dispatch_job_failure' : 'dispatch_escalation', $booking);
    }

    /**
     * REF 1CF-IMPLEMENT-20260922-L01 — payment-failure visibility is a
     * release safeguard, not optional: DispatchDeadlineSweepService's
     * T+30 auto-cancel now survives a failing refund (the cancellation
     * itself is no longer rolled back — see that class's own docblock),
     * but "survives silently" still leaves real money stuck needing
     * manual reconciliation with nobody told. Same scoped fan-out as
     * dispatchEscalation() above, for the same reason (franchise-scoped,
     * not platform-wide).
     */
    public function autoCancelRefundFailed(Booking $booking): void
    {
        $this->fanOutScoped('dispatch_refund_failed', $booking);
    }

    /**
     * REF 1CF-SCHEDULING-DISPATCH-001 (Part "B" early-warning milestone) —
     * scheduled_at minus the admin-configurable early-warning-hours
     * (default 3), still unassigned. Same scoped fan-out as
     * dispatchEscalation() — franchise-scoped, not platform-wide.
     */
    public function scheduledEarlyWarning(Booking $booking): void
    {
        $this->fanOutScoped('scheduled_early_warning', $booking);
    }

    /**
     * REF 1CF-SCHEDULING-DISPATCH-001 — the urgent milestone at
     * scheduled_at minus the unified customer-scheduling buffer (Part 1),
     * still unassigned. Admin-only — no customer copy at this point (the
     * customer's own "still finding your professional" notice already
     * went out at the earlier early-warning milestone).
     */
    public function scheduledUrgentAlert(Booking $booking): void
    {
        $this->fanOutScoped('scheduled_urgent_alert', $booking);
    }

    /** REF 1CF-JOURNEY-001 — a job in progress was flagged (provider left / cannot continue). Scoped like the dispatch alerts. */
    public function jobAtRisk(Booking $booking): void
    {
        $this->fanOutScoped('job_at_risk', $booking);
    }

    /** REF 1CF-CANCEL-POLICY-001 — `cancel_charge_unpaid` | `interim_dispute` | `cancel_payout_failed`. Scoped like the dispatch alerts. */
    public function cancellationEvent(string $event, Booking $booking): void
    {
        $this->fanOutScoped($event, $booking);
    }

    private function fanOutScoped(string $event, Booking $booking): void
    {
        $channels = array_values(array_intersect(ChannelResolver::resolve([]), [PushChannel::class]));

        if (empty($channels)) {
            return;
        }

        $booking->loadMissing('franchise');

        $scope = array_filter([
            'zone_id' => $booking->zone_id,
            'franchise_id' => $booking->franchise_id,
            'city_id' => $booking->franchise?->city_id,
            'country_id' => $booking->franchise?->country_id,
        ]);

        $authz = app(\App\Services\AuthorizationService::class);

        User::query()
            ->where('push_ops_alerts', true)
            ->whereNotNull('fcm_token')
            ->chunkById(200, function ($admins) use ($event, $booking, $channels, $authz, $scope) {
                foreach ($admins as $admin) {
                    if (! $authz->can($admin, 'operations.view', $scope)) {
                        continue;
                    }

                    try {
                        $admin->notify(new AdminOpsAlertNotification($event, $booking, $channels));
                    } catch (\Throwable $e) {
                        Log::warning('AdminOpsAlertService: failed to queue scoped alert.', [
                            'event' => $event,
                            'admin_id' => $admin->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    private function fanOut(string $event, Booking|Payment $subject): void
    {
        // Push ONLY — this is an operational nudge, not an inbox item, and
        // AdminOpsAlertNotification deliberately has no toMail(). Still
        // gated on the global notifications.channels setting: intersecting
        // with the resolved list means turning push off platform-wide
        // silences these too, rather than bypassing the setting.
        $channels = array_values(array_intersect(ChannelResolver::resolve([]), [PushChannel::class]));

        if (empty($channels)) {
            return;
        }

        User::query()
            ->where('push_ops_alerts', true)
            ->whereNotNull('fcm_token')
            ->chunkById(200, function ($admins) use ($event, $subject, $channels) {
                foreach ($admins as $admin) {
                    try {
                        $admin->notify(new AdminOpsAlertNotification($event, $subject, $channels));
                    } catch (\Throwable $e) {
                        Log::warning('AdminOpsAlertService: failed to queue alert.', [
                            'event' => $event,
                            'admin_id' => $admin->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }
}
