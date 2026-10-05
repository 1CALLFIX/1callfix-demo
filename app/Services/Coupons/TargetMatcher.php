<?php

namespace App\Services\Coupons;

use App\Models\Booking;
use App\Models\Coupon;
use App\Models\CouponTarget;
use App\Models\CouponUsage;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Targeting rules (design §4). Per target_type: if the coupon has ANY include
 * row of that type the subject must match at least one; ANY matching exclude
 * rejects; no rows of a type = no constraint on that axis. An unknown
 * target_type fails closed (an include that can never match, an exclude that
 * matches nothing is harmless) so a bad row can never widen a coupon.
 *
 * Context-level types gate the whole coupon; line-level types decide which
 * lines the discount applies to.
 */
class TargetMatcher
{
    public const CONTEXT_TYPES = ['city', 'zone', 'franchise', 'module', 'customer', 'customer_type'];

    public const LINE_TYPES = ['service_category', 'service_subcategory', 'service'];

    /** Explicit "Global / all eligible scope" marker row (hardening §E). Reaches only LIVE franchises (§Q). */
    public const GLOBAL_TYPE = 'global';

    /** Include rows of these types are an explicit scope on their own. customer_type alone is not (it is an audience, not a scope). */
    private const SCOPING_TYPES = ['city', 'zone', 'franchise', 'customer'];

    /**
     * Blank targeting never means "everywhere". A coupon is scoped only when it is owned by a franchise,
     * has an include row on city / zone / franchise / customer, or carries the explicit global marker.
     */
    public function hasExplicitScope(Coupon $coupon, Collection $targets): bool
    {
        if ($coupon->franchise_id !== null) {
            return true;
        }

        return $targets->contains(fn (CouponTarget $t) => $t->operator === 'include'
            && ($t->target_type === self::GLOBAL_TYPE || in_array($t->target_type, self::SCOPING_TYPES, true)));
    }

    public function isGlobal(Collection $targets): bool
    {
        return $targets->contains(fn (CouponTarget $t) => $t->target_type === self::GLOBAL_TYPE && $t->operator === 'include');
    }

    /** @return ?string null when the whole-coupon context passes, else the reason code */
    public function contextRejection(Coupon $coupon, PromotionContext $ctx, Collection $targets): ?string
    {
        foreach (self::CONTEXT_TYPES as $type) {
            $rows = $targets->where('target_type', $type);
            if ($rows->isEmpty()) {
                continue;
            }

            $includes = $rows->where('operator', 'include');
            $excludes = $rows->where('operator', 'exclude');

            if ($excludes->contains(fn (CouponTarget $t) => $this->contextMatches($t, $ctx))) {
                return 'excluded';
            }
            if ($includes->isNotEmpty() && ! $includes->contains(fn (CouponTarget $t) => $this->contextMatches($t, $ctx))) {
                return 'not_targeted';
            }
        }

        return null;
    }

    /** Does this line pass every line-level rule? */
    public function lineEligible(array $line, Collection $targets): bool
    {
        foreach (self::LINE_TYPES as $type) {
            $rows = $targets->where('target_type', $type);
            if ($rows->isEmpty()) {
                continue;
            }

            $includes = $rows->where('operator', 'include');
            $excludes = $rows->where('operator', 'exclude');

            if ($excludes->contains(fn (CouponTarget $t) => $this->lineMatches($t, $line))) {
                return false;
            }
            if ($includes->isNotEmpty() && ! $includes->contains(fn (CouponTarget $t) => $this->lineMatches($t, $line))) {
                return false;
            }
        }

        return true;
    }

    private function contextMatches(CouponTarget $t, PromotionContext $ctx): bool
    {
        return match ($t->target_type) {
            'city' => $ctx->cityId !== null && (int) $t->target_id === $ctx->cityId,
            'zone' => $ctx->zoneId !== null && (int) $t->target_id === $ctx->zoneId,
            'franchise' => $ctx->franchiseId !== null && (int) $t->target_id === $ctx->franchiseId,
            'module' => (string) ($t->params['module'] ?? '') === $ctx->module,
            'customer' => (int) $t->target_id === $ctx->customer->id,
            'customer_type' => $this->customerTypeMatches((array) ($t->params ?? []), $ctx),
            default => false,
        };
    }

    private function lineMatches(CouponTarget $t, array $line): bool
    {
        return match ($t->target_type) {
            'service_category' => isset($line['category_id']) && (int) $t->target_id === (int) $line['category_id'],
            'service_subcategory' => isset($line['subcategory_id']) && (int) $t->target_id === (int) $line['subcategory_id'],
            'service' => isset($line['service_id']) && (int) $t->target_id === (int) $line['service_id'],
            default => false,
        };
    }

    /** params: {"type":"new|returning|prime|inactive","days":N} */
    private function customerTypeMatches(array $params, PromotionContext $ctx): bool
    {
        $user = $ctx->customer;

        return match ($params['type'] ?? null) {
            'new' => $this->isNewCustomer($user, $ctx->ignoreBookingIds),
            'returning' => Booking::where('customer_id', $user->id)->where('status', 'completed')->exists(),
            'prime' => Subscription::where('subscribable_type', User::class)
                ->where('subscribable_id', $user->id)
                ->whereIn('status', ['active', 'grace_period'])
                ->exists(),
            'inactive' => $this->isInactive($user, (int) ($params['days'] ?? 0)),
            default => false,
        };
    }

    /** New = no non-cancelled booking (other than the one being created) and no counting coupon usage. */
    private function isNewCustomer(User $user, array $ignoreBookingIds): bool
    {
        $bookings = Booking::where('customer_id', $user->id)->where('status', '!=', 'cancelled');
        if ($ignoreBookingIds !== []) {
            $bookings->whereNotIn('id', $ignoreBookingIds);
        }

        return ! $bookings->exists()
            && ! CouponUsage::where('user_id', $user->id)
                ->whereIn('status', CouponUsage::COUNTING)
                ->when($ignoreBookingIds !== [], fn ($q) => $q->where(fn ($qq) => $qq->whereNull('booking_id')->orWhereNotIn('booking_id', $ignoreBookingIds)))
                ->exists();
    }

    private function isInactive(User $user, int $days): bool
    {
        if ($days <= 0) {
            return false;
        }

        $last = Booking::where('customer_id', $user->id)->where('status', 'completed')->max('completed_at');

        return $last !== null && now()->diffInDays($last, true) >= $days;
    }
}
