<?php

namespace App\Services\Plans;

use App\Models\PlanEntitlement;

/**
 * Zero-rejection principle (approved plan §9 / amendment 11): quota
 * exhaustion changes WHICH price applies, it never blocks the booking.
 * Always returns a usable price — never null, never throws.
 */
class OverageService
{
    /** @return array{price: float, was_overage: bool, overage_amount: ?float} */
    public function priceForExhaustedEntitlement(PlanEntitlement $entitlement, float $paygPrice): array
    {
        if (! $entitlement->overage_enabled) {
            // Overage disabled -> fall back to today's unmodified PAYG price. Still not a rejection.
            return ['price' => $paygPrice, 'was_overage' => false, 'overage_amount' => null];
        }

        $price = match ($entitlement->overage_rate_type) {
            'flat' => (float) $entitlement->overage_rate_value,
            'percentage_of_payg' => round($paygPrice * ((float) $entitlement->overage_rate_value / 100), 2),
            default => $paygPrice,
        };

        return ['price' => $price, 'was_overage' => true, 'overage_amount' => $price];
    }
}
