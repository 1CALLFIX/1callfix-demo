<?php

namespace App\Services\Plans;

use App\Models\BusinessAccount;
use App\Models\EntitlementBalance;
use App\Models\Payment;
use App\Models\PlanEntitlement;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\Support\ChannelResolver;
use App\Notifications\SubscriptionStatusNotification;
use Illuminate\Support\Facades\DB;

/**
 * Driven by the RenewDuePlans scheduled command (same Schedule::command()
 * mechanism DispatchDueCampaigns already proved — no new scheduler). Closes
 * expired periods, applies rollover, applies any queued upgrade/downgrade,
 * and drives active -> past_due/grace_period -> expired.
 *
 * No stored-payment-method / auto-debit gateway exists in this app (same
 * honest limitation as PayoutService's no-live-gateway design) — a
 * price > 0 plan cannot be silently auto-charged on renewal. It moves to
 * past_due/grace_period and the subscriber (or an admin) completes payment
 * via POST /subscriptions/{id}/renew-now, which re-enters the same
 * Razorpay order/webhook path a fresh purchase uses.
 */
class RenewalService
{
    public function __construct(private UsageService $usageService)
    {
    }

    /** @return array<string,int> counts by outcome, for the command's summary output */
    public function processDueSubscriptions(): array
    {
        $counts = [];

        $due = Subscription::whereIn('status', ['active', 'grace_period', 'past_due'])
            ->where('current_period_end', '<=', now())
            ->get();

        foreach ($due as $subscription) {
            $outcome = $this->processOne($subscription);
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }

        return $counts;
    }

    private function processOne(Subscription $subscription): string
    {
        if (in_array($subscription->status, ['past_due', 'grace_period'], true)) {
            $deadline = $subscription->grace_period_ends_at ?? $subscription->current_period_end;
            if (now()->greaterThanOrEqualTo($deadline)) {
                $this->closeoutExpire($subscription);

                return 'expired';
            }

            return 'still_in_grace';
        }

        if (! $subscription->auto_renew) {
            $this->closeoutExpire($subscription);

            return 'expired';
        }

        if ($subscription->pending_plan_id) {
            $this->applyPendingChange($subscription);
        }

        $plan = $subscription->plan()->first();

        if ((float) $plan->price <= 0) {
            $this->renewPeriod($subscription);

            return 'renewed';
        }

        $this->markPastDue($subscription);

        return 'moved_to_past_due';
    }

    private function renewPeriod(Subscription $subscription): void
    {
        DB::transaction(function () use ($subscription) {
            $subscription = Subscription::lockForUpdate()->findOrFail($subscription->id);
            // Re-read plan() AFTER applyPendingChange() (called by the caller
            // before this) may have already swapped plan_id -- this is
            // deliberately the CURRENT plan, not the one the closing
            // balances were granted under.
            $plan = $subscription->plan()->with('entitlements')->first();

            $newStart = $subscription->current_period_end ?? now();
            $newEnd = $plan->computePeriodEnd($newStart);

            $oldBalances = EntitlementBalance::where('subscription_id', $subscription->id)->where('status', 'current')->get();

            // Rollover only carries between two balances backed by the SAME
            // plan_entitlement_id — i.e. only when the plan itself didn't
            // change this period. When an upgrade/downgrade was just applied
            // (see RenewalService::processOne()'s applyPendingChange() call),
            // the new plan's entitlement IDs never match the old plan's —
            // the old balance's remainder is closed out/forfeited via the
            // same auditable 'expire' event a rollover_policy=none close
            // uses, never silently carried into a different plan's benefit
            // definition. The new period's balances always come from the
            // CURRENT plan's own entitlements, not the previous plan's.
            $rolloverByEntitlementId = [];
            foreach ($oldBalances as $old) {
                $stillOnSamePlan = $plan->entitlements->contains('id', $old->plan_entitlement_id);
                [$rolloverQty, $rolloverVal] = $stillOnSamePlan
                    ? $this->computeRollover($old, $old->planEntitlement)
                    : [0, 0.0];
                $rolloverByEntitlementId[$old->plan_entitlement_id] = [$rolloverQty, $rolloverVal];

                $remainingQty = max(0, $old->remainingQuantity());
                $remainingVal = max(0, $old->remainingMonetaryValue());
                if ($remainingQty > $rolloverQty || $remainingVal > $rolloverVal) {
                    $this->usageService->expire(
                        $old,
                        max(0, $remainingQty - $rolloverQty),
                        max(0, $remainingVal - $rolloverVal),
                        $stillOnSamePlan
                            ? 'Period closed, rollover_policy='.$old->planEntitlement->rollover_policy
                            : 'Plan changed at renewal — previous entitlement definition no longer applies'
                    );
                }

                $old->status = 'closed';
                $old->save();
            }

            foreach ($plan->entitlements as $entitlement) {
                [$rolloverQty, $rolloverVal] = $rolloverByEntitlementId[$entitlement->id] ?? [0, 0.0];
                $oldMatch = $oldBalances->firstWhere('plan_entitlement_id', $entitlement->id);

                $new = EntitlementBalance::create([
                    'subscription_id' => $subscription->id,
                    'plan_entitlement_id' => $entitlement->id,
                    'period_start' => $newStart,
                    'period_end' => $newEnd,
                    'granted_quantity' => $entitlement->quantity ?? 0,
                    'granted_monetary_value' => $entitlement->monetary_value ?? 0,
                    'rolled_over_quantity' => $rolloverQty,
                    'rolled_over_monetary_value' => $rolloverVal,
                    'rollover_expires_at' => $entitlement->rollover_expiry_days
                        ? $newStart->copy()->addDays($entitlement->rollover_expiry_days)
                        : null,
                    'status' => 'current',
                ]);

                if ($oldMatch && ($rolloverQty > 0 || $rolloverVal > 0)) {
                    $this->usageService->rollover($oldMatch, $new, $rolloverQty, $rolloverVal);
                }
            }

            $subscription->status = 'active';
            $subscription->current_period_start = $newStart;
            $subscription->current_period_end = $newEnd;
            $subscription->grace_period_ends_at = null;
            $subscription->save();
        });

        $this->notify($subscription->fresh(), 'renewed');
    }

    /** @return array{0:int,1:float} [rollover_quantity, rollover_monetary_value] */
    private function computeRollover(EntitlementBalance $old, PlanEntitlement $entitlement): array
    {
        $remainingQty = max(0, $old->remainingQuantity());
        $remainingVal = max(0, $old->remainingMonetaryValue());

        return match ($entitlement->rollover_policy) {
            'full' => [$remainingQty, $remainingVal],
            'partial' => [
                $entitlement->rollover_cap !== null ? min($remainingQty, $entitlement->rollover_cap) : $remainingQty,
                $entitlement->rollover_cap !== null ? min($remainingVal, (float) $entitlement->rollover_cap) : $remainingVal,
            ],
            default => [0, 0.0], // 'none' -- nothing carries; the caller records the forfeited remainder as an 'expire' ledger event, never a silent drop
        };
    }

    private function markPastDue(Subscription $subscription): void
    {
        $graceDays = (int) Setting::get('plan.grace_period_days', '0', []);

        $subscription->status = $graceDays > 0 ? 'grace_period' : 'past_due';
        $subscription->grace_period_ends_at = $graceDays > 0 ? now()->addDays($graceDays) : now();
        $subscription->save();

        $this->notify($subscription->fresh(), 'renewal_failed');
    }

    private function closeoutExpire(Subscription $subscription): void
    {
        DB::transaction(function () use ($subscription) {
            $subscription = Subscription::lockForUpdate()->findOrFail($subscription->id);
            EntitlementBalance::where('subscription_id', $subscription->id)->where('status', 'current')->update(['status' => 'closed']);
            $subscription->status = 'expired';
            $subscription->expires_at = now();
            $subscription->save();
        });

        $this->notify($subscription->fresh(), 'expired');
    }

    private function applyPendingChange(Subscription $subscription): void
    {
        $subscription->plan_id = $subscription->pending_plan_id;
        $subscription->pending_plan_id = null;
        $subscription->pending_change_type = null;
        $subscription->pending_change_effective_at = null;
        $subscription->save();
    }

    private function notify(Subscription $subscription, string $event): void
    {
        $notifiable = $subscription->subscribable instanceof User
            ? $subscription->subscribable
            : ($subscription->subscribable instanceof BusinessAccount ? $subscription->subscribable->owner : null);

        if (! $notifiable) {
            return;
        }

        $channels = ChannelResolver::resolve([]);
        $notifiable->notify(new SubscriptionStatusNotification($event, $subscription, $channels));
    }
}
