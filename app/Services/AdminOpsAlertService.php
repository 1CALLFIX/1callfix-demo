<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Setting;
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
    /**
     * Critical alert types that are also emailed to admins (0d). Key = the
     * setting suffix (alerts.email.<key>), value = label on the Alert Emails
     * screen. Default ON when the setting was never saved. Email is
     * independent of push: no opt-in flag and no fcm_token needed.
     */
    public const EMAIL_TYPES = [
        'payment_amount_mismatch' => 'Payment amount mismatch',
        'refund_failed' => 'Refund failed (any refund path)',
        'mismatch_refund_escalation' => 'Mismatch refund approval escalation',
        'cancel_payout_failed' => 'Cancellation payout failed',
        'dispatch_job_failure' => 'Dispatch job failure',
    ];

    public static function emailSettingKey(string $type): string
    {
        return "alerts.email.{$type}";
    }

    public function emailEnabled(string $type): bool
    {
        if (! array_key_exists($type, self::EMAIL_TYPES)) {
            return false;
        }

        $value = Setting::get(self::emailSettingKey($type));

        return ($value === null || trim((string) $value) === '') ? true : (bool) (int) $value;
    }

    /**
     * Emails the alert to every admin account (Super Admin or anyone holding
     * a role assignment) for whom $eligible() is true — the same audience
     * test the push fan-out applies, minus the push opt-in and fcm_token.
     * Suspended and email-less accounts are skipped. Best-effort per admin.
     *
     * @param  callable(User): bool  $eligible
     */
    private function emailTo(string $type, string $event, \Illuminate\Database\Eloquent\Model $subject, callable $eligible): void
    {
        if (! $this->emailEnabled($type)) {
            return;
        }

        User::query()
            ->whereNotNull('email')->where('email', '!=', '')
            ->where('status', '!=', 'suspended')
            ->where(fn ($q) => $q->where('role', 'super_admin')->orWhereHas('roleAssignments'))
            ->chunkById(200, function ($admins) use ($type, $event, $subject, $eligible) {
                foreach ($admins as $admin) {
                    try {
                        if (! $eligible($admin)) {
                            continue;
                        }

                        $admin->notify(new AdminOpsAlertNotification($event, $subject, ['mail']));
                    } catch (\Throwable $e) {
                        Log::warning('AdminOpsAlertService: failed to queue alert email.', [
                            'type' => $type,
                            'event' => $event,
                            'admin_id' => $admin->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    private function bookingScope(Booking $booking): array
    {
        $booking->loadMissing('franchise');

        return array_filter([
            'zone_id' => $booking->zone_id,
            'franchise_id' => $booking->franchise_id,
            'city_id' => $booking->franchise?->city_id,
            'country_id' => $booking->franchise?->country_id,
        ]);
    }

    /** Email audience for the booking-scoped alerts: operations.view covering the booking (Super Admin always). */
    private function emailToBookingOperators(string $type, string $event, Booking $booking): void
    {
        $authz = app(\App\Services\AuthorizationService::class);
        $scope = $this->bookingScope($booking);

        $this->emailTo($type, $event, $booking, fn (User $admin) => $authz->can($admin, 'operations.view', $scope));
    }

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

        // Email: whoever may act on it — payments.refund_mismatch over this payment's franchise (Super Admin always).
        $queue = app(\App\Services\Payments\MismatchRefundService::class);
        $franchiseId = $queue->franchiseIdFor($payment);

        $this->emailTo('payment_amount_mismatch', 'payment_amount_mismatch', $payment, fn (User $admin) => $queue->levelOf($admin, $franchiseId) !== null);
    }

    /**
     * A gateway refund call failed (RefundAlertingGateway — covers every
     * refund path, including ones that catch and only log). Email-only:
     * operations.view over the payment's franchise (Super Admin always);
     * an unknown or franchise-less payment reaches global holders only.
     */
    public function refundFailed(string $gatewayPaymentId): void
    {
        $payment = Payment::where('gateway_payment_id', $gatewayPaymentId)->latest('id')->first()
            ?? new Payment(['gateway_payment_id' => $gatewayPaymentId]);

        $queue = app(\App\Services\Payments\MismatchRefundService::class);
        $authz = app(\App\Services\AuthorizationService::class);
        $scope = $queue->scopeFor($payment->exists ? $queue->franchiseIdFor($payment) : null);

        $this->emailTo('refund_failed', 'refund_failed', $payment, fn (User $admin) => $authz->can($admin, 'operations.view', $scope));
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

        if ($jobFailed) {
            $this->emailToBookingOperators('dispatch_job_failure', 'dispatch_job_failure', $booking);
        }
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
        $this->emailToBookingOperators('refund_failed', 'dispatch_refund_failed', $booking);
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

        if ($event === 'cancel_payout_failed') {
            $this->emailToBookingOperators('cancel_payout_failed', 'cancel_payout_failed', $booking);
        }
    }

    /**
     * MANUAL MONEY ACTIONS escalation — a mismatch refund has sat open past
     * refund.mismatch.escalate_after_hours. Goes ONLY to holders of the
     * target level for that row's franchise (1 franchise, 2 HQ, 3 Super
     * Admin), never platform-wide, so many branches stay quiet.
     */
    public function mismatchRefundEscalation(\App\Models\MismatchRefund $refund, int $level): void
    {
        $queue = app(\App\Services\Payments\MismatchRefundService::class);
        $this->emailTo('mismatch_refund_escalation', 'mismatch_refund_escalation', $refund, fn (User $admin) => $queue->levelOf($admin, $refund->franchise_id) === $level);

        $channels = array_values(array_intersect(ChannelResolver::resolve([]), [PushChannel::class]));

        if (empty($channels)) {
            return;
        }

        $service = app(\App\Services\Payments\MismatchRefundService::class);

        User::query()
            ->where('push_ops_alerts', true)
            ->whereNotNull('fcm_token')
            ->chunkById(200, function ($admins) use ($refund, $level, $channels, $service) {
                foreach ($admins as $admin) {
                    if ($service->levelOf($admin, $refund->franchise_id) !== $level) {
                        continue;
                    }

                    try {
                        $admin->notify(new AdminOpsAlertNotification('mismatch_refund_escalation', $refund, $channels));
                    } catch (\Throwable $e) {
                        Log::warning('AdminOpsAlertService: failed to queue mismatch refund escalation.', [
                            'refund_id' => $refund->id,
                            'admin_id' => $admin->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
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
