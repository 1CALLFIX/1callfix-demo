<?php

namespace App\Services\Coupons;

use App\Exceptions\CouponException;
use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\Coupon;
use App\Models\CouponUsage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The coupon engine (docs/COUPON_ENGINE_DESIGN.md). One public surface:
 *
 *   validate(ctx)               read-only, writes nothing
 *   reserve(ctx, booking|bundle) inside the booking transaction, under the coupon row lock
 *   confirm / release / consume  usage lifecycle, driven by BookingObserver
 *
 * THUMB RULE (CLAUDE.md): a coupon is a benefit, so validate() AND reserve()
 * reject any payment method that is not online/wallet — server-side, in the
 * engine, so web, API, Flutter and admin all inherit it. There is no
 * per-booking override anywhere in this class.
 *
 * Price contract (decision D1): price_quoted stays GROSS; the discount is a
 * separate column. Commission, payout, fee bases are untouched.
 */
class CouponService
{
    public const ONLINE_METHODS = ['online', 'wallet'];

    /** Razorpay cannot create an order below ₹1 and a zero wallet debit is meaningless — technical floor on what is left to pay. */
    private const MIN_PAYABLE = 1.00;

    public function __construct(private TargetMatcher $matcher)
    {
    }

    // ───────────────────────────── validate ─────────────────────────────

    public function validate(PromotionContext $ctx, ?Coupon $lockedCoupon = null): PromotionResult
    {
        if (! CouponSettings::available()) {
            return PromotionResult::reject('coupons_unavailable', 'Coupons are not available right now.');
        }

        if (! in_array($ctx->paymentMethod, self::ONLINE_METHODS, true)) {
            return PromotionResult::reject('online_payment_required', 'Coupons are valid only for online payments');
        }

        $code = mb_strtolower(trim((string) $ctx->code));
        if ($code === '') {
            return PromotionResult::reject('invalid_code', 'This coupon code is not valid.');
        }

        $coupon = $lockedCoupon ?? Coupon::whereRaw('LOWER(code) = ?', [$code])->first();
        if (! $coupon || mb_strtolower($coupon->code) !== $code) {
            return PromotionResult::reject('invalid_code', 'This coupon code is not valid.');
        }

        if ($coupon->status !== 'active' || ! $coupon->is_active) {
            return match ($coupon->status) {
                'exhausted' => PromotionResult::reject('exhausted', 'This coupon has been fully redeemed.', $coupon),
                'expired' => PromotionResult::reject('expired', 'This coupon has expired.', $coupon),
                default => PromotionResult::reject('inactive', 'This coupon is not active.', $coupon),
            };
        }

        if ($coupon->valid_from && $coupon->valid_from->isFuture()) {
            return PromotionResult::reject('not_started', 'This coupon is not valid yet.', $coupon);
        }
        if ($coupon->valid_until && $coupon->valid_until->isPast()) {
            return PromotionResult::reject('expired', 'This coupon has expired.', $coupon);
        }

        if ($coupon->module !== $ctx->module) {
            return PromotionResult::reject('not_targeted', 'This coupon does not apply here.', $coupon);
        }
        if ($coupon->franchise_id !== null && (int) $coupon->franchise_id !== (int) $ctx->franchiseId) {
            return PromotionResult::reject('not_targeted', 'This coupon does not apply here.', $coupon);
        }

        $targets = $coupon->targets()->get();

        if ($rejection = $this->matcher->contextRejection($coupon, $ctx, $targets)) {
            return PromotionResult::reject($rejection, 'This coupon does not apply to this order.', $coupon);
        }

        // Line-level: which lines the discount can touch.
        $eligible = [];
        $blockedByFlash = false;
        $blockedByEntitlement = false;
        foreach ($ctx->lines as $line) {
            if (! empty($line['entitlement_covered'])) {
                $blockedByEntitlement = true;

                continue;
            }
            if (! empty($line['flash_applied']) && ! $coupon->stackable_with_flash) {
                $blockedByFlash = true;

                continue;
            }
            if (! $this->matcher->lineEligible($line, $targets)) {
                continue;
            }
            $eligible[] = $line;
        }

        if ($eligible === []) {
            if ($blockedByEntitlement) {
                return PromotionResult::reject('entitlement_covered', 'Your membership benefit already covers this booking.', $coupon);
            }
            if ($blockedByFlash) {
                return PromotionResult::reject('flash_sale_conflict', 'This coupon cannot be combined with the sale price.', $coupon);
            }

            return PromotionResult::reject('not_targeted', 'This coupon does not apply to this order.', $coupon);
        }

        $base = round(array_sum(array_map(fn ($l) => (float) $l['line_total'], $eligible)), 2);

        if ($base < (float) $coupon->min_order_value) {
            return PromotionResult::reject('below_minimum', 'The order value is below this coupon\'s minimum.', $coupon);
        }

        // Limits. Counted from the usage rows (reserved + confirmed + consumed) so the
        // answer is self-healing; authoritative when $lockedCoupon holds the row lock.
        $counting = CouponUsage::where('coupon_id', $coupon->id)->whereIn('status', CouponUsage::COUNTING);

        if ($coupon->usage_limit !== null && (clone $counting)->count() >= (int) $coupon->usage_limit) {
            return PromotionResult::reject('exhausted', 'This coupon has been fully redeemed.', $coupon);
        }
        if ((clone $counting)->where('user_id', $ctx->customer->id)->count() >= (int) $coupon->per_user_limit) {
            return PromotionResult::reject('over_per_user_limit', 'You have already used this coupon.', $coupon);
        }

        $discount = $this->rawDiscount($coupon, $base);
        // Never leave less than the technical minimum to pay on the whole order.
        $discount = min($discount, max(round($ctx->subtotal() - self::MIN_PAYABLE, 2), 0));
        $discount = round($discount, 2);

        if ($discount <= 0) {
            return PromotionResult::reject('no_discount', 'This coupon gives no discount on this order.', $coupon);
        }

        if ($coupon->total_budget !== null) {
            $spent = (float) (clone $counting)->whereIn('status', ['reserved', 'confirmed'])->sum('discount_applied');
            if (round($spent + $discount, 2) > (float) $coupon->total_budget) {
                return PromotionResult::reject('budget_exhausted', 'This coupon has been fully redeemed.', $coupon);
            }
        }

        // Q9 daily cap: reservations count, releases give it back; resumes by itself at 00:00 IST
        // (nothing is written — "today" simply moves on). Computed from usage rows, no counter.
        if ($coupon->daily_budget !== null) {
            $todayStart = now('Asia/Kolkata')->startOfDay()->utc();
            $today = (float) CouponUsage::where('coupon_id', $coupon->id)
                ->whereIn('status', ['reserved', 'confirmed'])
                ->where('reserved_at', '>=', $todayStart)
                ->sum('discount_applied');
            if (round($today + $discount, 2) > (float) $coupon->daily_budget) {
                return PromotionResult::reject('daily_cap_reached', "Today's limit for this coupon has been reached. Please try again tomorrow.", $coupon);
            }
        }

        $allocations = $this->allocate($eligible, $base, $discount);

        return new PromotionResult(
            eligible: true,
            reasonCode: 'ok',
            message: 'Coupon applied.',
            discountTotal: $discount,
            lineAllocations: $allocations,
            snapshot: [
                'rule_version' => 1,
                'coupon_id' => $coupon->id,
                'code' => $coupon->code,
                'name' => $coupon->name,
                'discount_type' => $coupon->discount_type,
                'value' => (float) $coupon->value,
                'max_discount' => $coupon->max_discount !== null ? (float) $coupon->max_discount : null,
                'min_order_value' => (float) $coupon->min_order_value,
                'stackable_with_flash' => (bool) $coupon->stackable_with_flash,
                'gross' => $ctx->subtotal(),
                'eligible_base' => $base,
                'discount' => $discount,
                'net' => round($ctx->subtotal() - $discount, 2),
                'allocations' => $allocations,
                'targets' => $targets->map(fn ($t) => $t->only(['target_type', 'target_id', 'operator', 'params']))->values()->all(),
                'benefit' => 'coupon',
                'evaluated_at' => now()->toIso8601String(),
            ],
            coupon: $coupon,
        );
    }

    private function rawDiscount(Coupon $coupon, float $base): float
    {
        $discount = $coupon->discount_type === 'percent'
            ? $base * ((float) $coupon->value / 100)
            : (float) $coupon->value;

        if ($coupon->max_discount !== null) {
            $discount = min($discount, (float) $coupon->max_discount);
        }

        return round(min($discount, $base), 2);
    }

    /**
     * Largest-remainder split in paise, so Σ allocations === discount exactly.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, float> line_ref => amount
     */
    public function allocate(array $lines, float $base, float $discount): array
    {
        $totalPaise = (int) round($discount * 100);
        $basePaise = max((int) round($base * 100), 1);

        $parts = [];
        $assigned = 0;
        foreach ($lines as $i => $line) {
            $exact = $totalPaise * ((float) $line['line_total'] * 100) / $basePaise;
            $floor = (int) floor($exact);
            $parts[$i] = ['floor' => $floor, 'rem' => $exact - $floor];
            $assigned += $floor;
        }

        $left = $totalPaise - $assigned;
        uasort($parts, fn ($a, $b) => $b['rem'] <=> $a['rem']);
        foreach ($parts as $i => &$p) {
            if ($left <= 0) {
                break;
            }
            $p['floor']++;
            $left--;
        }
        unset($p);

        $out = [];
        foreach ($lines as $i => $line) {
            $out[(string) $line['line_ref']] = round($parts[$i]['floor'] / 100, 2);
        }

        return $out;
    }

    // ───────────────────────────── reserve ─────────────────────────────

    /**
     * Revalidates under the coupon row lock and writes the usage. MUST run
     * inside the booking's transaction so a failed payment/wallet debit rolls
     * the reservation back too.
     *
     * @throws CouponException when the coupon is not (or no longer) usable
     */
    public function reserve(PromotionContext $ctx, ?Booking $booking = null, ?BookingBundle $bundle = null): array
    {
        return DB::transaction(function () use ($ctx, $booking, $bundle) {
            $pre = $this->validate($ctx);
            if (! $pre->eligible) {
                throw new CouponException($pre->reasonCode, $pre->message);
            }

            // Fixed lock order: coupon row first.
            $coupon = Coupon::lockForUpdate()->findOrFail($pre->coupon->id);
            $result = $this->validate($ctx, $coupon);
            if (! $result->eligible) {
                throw new CouponException($result->reasonCode, $result->message);
            }

            $usage = CouponUsage::create([
                'coupon_id' => $coupon->id,
                'user_id' => $ctx->customer->id,
                'booking_id' => $booking?->id,
                'booking_bundle_id' => $bundle?->id,
                'discount_applied' => $result->discountTotal,
                'status' => 'reserved',
                'original_amount' => $ctx->subtotal(),
                'net_amount' => round($ctx->subtotal() - $result->discountTotal, 2),
                'snapshot' => $result->snapshot,
                'reserved_at' => now(),
            ]);

            $this->recompute($coupon);

            return [$result, $usage];
        });
    }

    // ───────────────────────── usage lifecycle ─────────────────────────

    public function confirm(CouponUsage $usage): void
    {
        $this->transition($usage, 'reserved', 'confirmed', 'confirmed_at');
    }

    public function release(CouponUsage $usage): void
    {
        $this->transition($usage, 'reserved', 'released', 'released_at');
    }

    /** Work had started: the discount is forfeited and the usage still counts toward limits. */
    public function consume(CouponUsage $usage): void
    {
        $this->transition($usage, 'reserved', 'consumed', 'released_at');
    }

    private function transition(CouponUsage $usage, string $from, string $to, string $stampColumn): void
    {
        DB::transaction(function () use ($usage, $from, $to, $stampColumn) {
            $coupon = Coupon::lockForUpdate()->find($usage->coupon_id);
            $locked = CouponUsage::lockForUpdate()->find($usage->id);

            if (! $coupon || ! $locked || $locked->status !== $from) {
                return; // idempotent: already moved on
            }

            $locked->status = $to;
            $locked->{$stampColumn} = now();
            $locked->save();

            $this->recompute($coupon);
        });
    }

    /** Booking finished: the benefit is realised. */
    public function onBookingCompleted(Booking $booking): void
    {
        if ($usage = $this->usageFor($booking)) {
            $this->confirm($usage);
        }
    }

    /**
     * Booking cancelled. Before work ($statusBefore not in_progress and never
     * was) => release; from in_progress onward => consume (Q5).
     */
    public function onBookingCancelled(Booking $booking, ?string $statusBefore): void
    {
        $usage = CouponUsage::where('booking_id', $booking->id)->where('status', 'reserved')->first();

        if ($usage) {
            $this->settleCancelled($usage, $this->workStarted($booking, $statusBefore));

            return;
        }

        // Bundle-level usage: settled only once every child is cancelled.
        if ($booking->booking_bundle_id) {
            $usage = CouponUsage::where('booking_bundle_id', $booking->booking_bundle_id)->where('status', 'reserved')->first();
            if (! $usage) {
                return;
            }

            $children = Booking::where('booking_bundle_id', $booking->booking_bundle_id)->get();
            if ($children->contains(fn (Booking $c) => $c->status !== 'cancelled')) {
                return;
            }

            $started = $children->contains(fn (Booking $c) => $this->workStarted($c, $c->id === $booking->id ? $statusBefore : null));
            $this->settleCancelled($usage, $started);
        }
    }

    private function settleCancelled(CouponUsage $usage, bool $workStarted): void
    {
        $workStarted ? $this->consume($usage) : $this->release($usage);
    }

    private function workStarted(Booking $booking, ?string $statusBefore): bool
    {
        return in_array($statusBefore, ['in_progress', 'disputed'], true)
            || $booking->statusHistory()->where('status', 'in_progress')->exists();
    }

    private function usageFor(Booking $booking): ?CouponUsage
    {
        return CouponUsage::where('status', 'reserved')
            ->where(fn ($q) => $q->where('booking_id', $booking->id)
                ->when($booking->booking_bundle_id, fn ($qq) => $qq->orWhere('booking_bundle_id', $booking->booking_bundle_id)))
            ->first();
    }

    /**
     * Rebuild the denormalised counters from the usage rows (caller holds the
     * coupon row lock) and flip active <-> exhausted when a cap is hit or
     * freed. Admin-set paused/draft/expired are never touched.
     */
    private function recompute(Coupon $coupon): void
    {
        $rows = CouponUsage::where('coupon_id', $coupon->id);

        $reserved = (clone $rows)->where('status', 'reserved');
        $confirmed = (clone $rows)->where('status', 'confirmed');

        $coupon->reserved_amount = (float) (clone $reserved)->sum('discount_applied');
        $coupon->confirmed_amount = (float) (clone $confirmed)->sum('discount_applied');
        $coupon->usage_count_reserved = (clone $reserved)->count();
        $coupon->usage_count_confirmed = (clone $confirmed)->count();

        $counting = (clone $rows)->whereIn('status', CouponUsage::COUNTING)->count();
        $atCap = ($coupon->usage_limit !== null && $counting >= (int) $coupon->usage_limit)
            || ($coupon->total_budget !== null
                && round($coupon->reserved_amount + $coupon->confirmed_amount, 2) >= (float) $coupon->total_budget);

        if ($coupon->status === 'active' && $atCap) {
            $coupon->status = 'exhausted';
            $coupon->is_active = false;
        } elseif ($coupon->status === 'exhausted' && ! $atCap) {
            // A released (abandoned/cancelled) reservation gave capacity back.
            $coupon->status = 'active';
            $coupon->is_active = true;
        }

        $coupon->save();
    }

    // ───────────────────────────── bundles ─────────────────────────────

    /**
     * One coupon per bundle, judged against the bundle's lines. Children keep
     * their gross price_quoted; each gets its allocated share of the discount.
     *
     * @param  Collection<int, Booking>  $children
     */
    public function applyToBundle(BookingBundle $bundle, Collection $children, string $code): PromotionResult
    {
        $ctx = app(ServicePromotionContextBuilder::class)->forBundle($bundle, $children, $code);

        [$result, $usage] = $this->reserve($ctx, bundle: $bundle);

        foreach ($children as $child) {
            $share = (float) ($result->lineAllocations[(string) $child->id] ?? 0);
            $child->coupon_id = $result->coupon->id;
            $child->coupon_discount_amount = $share;
            $child->coupon_snapshot = $result->snapshot + ['child_discount' => $share];
            $child->save();
        }

        $bundle->coupon_id = $result->coupon->id;
        $bundle->coupon_discount_amount = $result->discountTotal;
        $bundle->coupon_snapshot = $result->snapshot;
        $bundle->save();

        return $result;
    }
}
