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
    /** The ONLY text a customer ever sees for a coupon that cannot be redeemed (hardening §H). */
    public const GENERIC_MESSAGE = 'This coupon cannot be applied to this order.';

    /** The kill switch and the cash rule are not about any one coupon, so they keep their own wording. */
    private const OWN_MESSAGE = ['coupons_unavailable', 'online_payment_required'];

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
        /** Internal-only precise wording of a rejection. Never shown to customers. */
        public readonly ?string $detail = null,
    ) {
    }

    /**
     * $message is the precise internal text (kept in `detail` for logs / audit / admin diagnostics); the
     * customer-facing `message` is always GENERIC_MESSAGE except for the two reasons in OWN_MESSAGE.
     */
    public static function reject(string $reason, string $message, ?Coupon $coupon = null): self
    {
        $own = in_array($reason, self::OWN_MESSAGE, true);

        return new self(false, $reason, $own ? $message : self::GENERIC_MESSAGE, coupon: $coupon, detail: $own ? null : $message);
    }
}
