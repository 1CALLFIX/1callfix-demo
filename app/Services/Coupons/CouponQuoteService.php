<?php

namespace App\Services\Coupons;

use App\Exceptions\CouponException;
use App\Models\Franchise;
use App\Models\Service;
use App\Models\User;
use App\Services\FlashSaleService;
use App\Services\Plans\EntitlementService;
use App\Services\Payments\OnlinePaymentGuard;
use App\Support\Modules;

/**
 * A read-only coupon preview for the customer: full price, discount and payable, all computed here from the
 * same cascade and the same engine the booking path uses (FlashSaleService::effectivePricesFor ->
 * CouponService::validate). The client sends only a code, services and a payment method; it never supplies an
 * amount. Writes nothing and reserves nothing; the booking itself re-judges and is the authority.
 */
class CouponQuoteService
{
    public const VISIT_CHARGE_NOTE = 'Visit and inspection charges are separate from coupon discounts.';

    public function __construct(
        private FlashSaleService $flashSales,
        private CouponService $coupons,
        private ServicePromotionContextBuilder $builder,
        private EntitlementService $entitlements,
    ) {
    }

    /**
     * @param  array<int, array{service: Service, quantity: int}>  $items
     * @return array{eligible: bool, message: ?string, full_price: float, subtotal: float, discount: float, payable: float, note: string}
     */
    public function quote(User $customer, ?int $franchiseId, ?int $zoneId, array $items, string $paymentMethod, string $code): array
    {
        $franchise = $franchiseId ? Franchise::find($franchiseId) : null;
        $scope = array_filter([
            'zone_id' => $zoneId, 'franchise_id' => $franchiseId,
            'city_id' => $franchise?->city_id, 'country_id' => $franchise?->country_id,
        ], fn ($v) => $v !== null);
        $online = OnlinePaymentGuard::isOnline($paymentMethod);

        $services = collect($items)->pluck('service')->unique('id')->values();
        $prices = $this->flashSales->effectivePricesFor($services, $franchiseId, $scope);

        $lines = [];
        $full = 0.0;
        foreach ($items as $item) {
            $p = $prices[$item['service']->id];
            for ($i = 0; $i < max(1, (int) $item['quantity']); $i++) {
                $unit = $online ? $p['price'] : $p['resolved_price'];
                $full += $p['resolved_price'];
                $lines[] = ['service' => $item['service'], 'unit_price' => (float) $unit, 'flash_applied' => $online && $p['sale'] !== null];
            }
        }
        $full = round($full, 2);
        $subtotal = round(array_sum(array_column($lines, 'unit_price')), 2);

        $ctx = $this->builder->forQuote($customer, $franchiseId, $zoneId, $franchise, $lines, $paymentMethod, $code);
        $result = $this->coupons->validate($ctx);

        if (! $result->eligible) {
            return $this->payload(false, CouponException::customerMessageFor($result->reasonCode), $full, $subtotal, 0.0);
        }

        $discount = $result->discountTotal;

        // One discount per booking (decision Q8): a single-booking quote mirrors the booking path, where a larger
        // member benefit wins and the coupon is not used. The payable can then only be lower than shown, never higher.
        if (count($lines) === 1) {
            $preview = $this->entitlements->previewBestPricingEntitlement($customer, $lines[0]['unit_price'], $paymentMethod);
            if ($preview && $preview['entitlement_type'] === 'quantity') {
                return $this->payload(false, CouponException::GENERIC_MESSAGE, $full, $subtotal, 0.0);
            }
            if ($preview && $preview['discount'] >= $discount) {
                $discount = $preview['discount'];
            }
        }

        return $this->payload(true, null, $full, $subtotal, $discount);
    }

    private function payload(bool $eligible, ?string $message, float $full, float $subtotal, float $discount): array
    {
        return [
            'eligible' => $eligible,
            'message' => $message,
            'full_price' => $full,
            'subtotal' => $subtotal,
            'discount' => round($discount, 2),
            'payable' => round(max(0.0, $subtotal - $discount), 2),
            'note' => self::VISIT_CHARGE_NOTE,
        ];
    }
}
