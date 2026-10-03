<?php

namespace App\Services\Coupons;

use App\Models\Coupon;

/**
 * Outcome of validate(). Reason codes are stable strings so Flutter / web can
 * localise: coupons_unavailable, online_payment_required, invalid_code,
 * inactive, not_started, expired, exhausted, budget_exhausted,
 * over_per_user_limit, below_minimum, not_targeted, excluded,
 * flash_sale_conflict, entitlement_covered, no_discount.
 */
final class PromotionResult
{
    /**
     * @param  array<string, float>  $lineAllocations  line_ref => discount
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(
        public readonly bool $eligible,
        public readonly string $reasonCode,
        public readonly string $message,
        public readonly float $discountTotal = 0.0,
        public readonly array $lineAllocations = [],
        public readonly array $snapshot = [],
        public readonly ?Coupon $coupon = null,
    ) {
    }

    public static function reject(string $reason, string $message, ?Coupon $coupon = null): self
    {
        return new self(false, $reason, $message, coupon: $coupon);
    }
}
