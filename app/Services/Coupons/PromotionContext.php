<?php

namespace App\Services\Coupons;

use App\Models\User;

/**
 * Everything the engine needs to judge a coupon. Modules own building this;
 * the engine owns the rules (design §5). Money here is always server-derived,
 * never client-supplied.
 *
 * lines[]: line_ref, category_id, subcategory_id, service_id, line_total,
 * flash_applied, entitlement_covered.
 */
final class PromotionContext
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function __construct(
        public readonly string $module,
        public readonly ?int $franchiseId,
        public readonly ?int $cityId,
        public readonly ?int $zoneId,
        public readonly User $customer,
        public readonly string $paymentMethod,
        public readonly array $lines,
        public readonly ?string $code,
        /** Bookings being created right now (excluded from "new customer" history). */
        public readonly array $ignoreBookingIds = [],
        public readonly ?int $countryId = null,
    ) {
    }

    /** Σ line_total after flash, before coupon. */
    public function subtotal(): float
    {
        return round(array_sum(array_map(fn ($l) => (float) $l['line_total'], $this->lines)), 2);
    }
}
