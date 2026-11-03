<?php

namespace App\Services\Food;

use App\Models\Address;
use App\Models\AddOn;
use App\Models\CartItem;
use App\Models\Store;
use App\Services\DispatchService;
use App\Support\FoodSettings;
use App\Support\SiteText;
use Illuminate\Support\Collection;

/**
 * Food F2 — the checks and charges a restaurant order has on top of a shop order. Called from
 * CreateMarketplaceOrderAction only when the store is a restaurant; every number comes from Food Settings or the
 * restaurant's own page. The browse pages hide closed restaurants and sold-out dishes too, but this is the check
 * that counts: the menu can change between adding to the cart and paying.
 */
class FoodCheckout
{
    public function __construct(private DispatchService $dispatch)
    {
    }

    public function assertRestaurantTakingOrders(Store $store): void
    {
        if ($store->missingFssai()) {
            throw new \RuntimeException(SiteText::get('food.checkout.not_taking'));
        }
        if (! $store->isOpenNow()) {
            throw new \RuntimeException(SiteText::get('food.cart.closed', ['restaurant' => $store->name]));
        }
    }

    /** Sold out today, and the add-on choices against each group's rule ("Base: pick 1."). */
    public function assertLines(Collection $lines): void
    {
        foreach ($lines as $line) {
            /** @var CartItem $line */
            $product = $line->product;
            if ($product->isSoldOutNow()) {
                throw new \RuntimeException(SiteText::get('food.checkout.sold_out', ['dish' => $product->name]));
            }

            $groups = $product->addOnGroups()->where('add_on_groups.is_active', true)->with('addOns')->get();
            $chosen = collect($line->add_on_ids ?? [])->map(fn ($id) => (int) $id)->unique()->values();

            if ($chosen->isNotEmpty()) {
                $allowed = AddOn::whereIn('add_on_group_id', $groups->pluck('id'))->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id);
                if ($chosen->diff($allowed)->isNotEmpty()) {
                    throw new \RuntimeException(SiteText::get('food.checkout.choice_gone', ['dish' => $product->name]));
                }
            }

            foreach ($groups as $group) {
                if ($group->problemWith($chosen->all()) !== null) {
                    throw new \RuntimeException(SiteText::get('food.checkout.group_rule', ['dish' => $product->name, 'group' => $group->name, 'rule' => FoodCart::ruleText($group)]));
                }
            }
        }
    }

    /** The restaurant's packaging charge, never above today's Food Settings limit (unset limit = no charge). */
    public function packagingCharge(Store $store): float
    {
        $max = FoodSettings::packagingChargeMax($store->settingsScope());
        if ($max === null) {
            return 0.0;
        }

        return round(min(max((float) $store->packaging_charge, 0), $max), 2);
    }

    /**
     * Distance to the address (only when the radius or the distance fee is set), the radius check, and the delivery
     * fee before the free-delivery rule. Returns [fee, km]. No distance fee set = the restaurant's own flat fee.
     *
     * @return array{0: float, 1: ?float}
     */
    public function deliveryFee(Store $store, Address $address): array
    {
        $scope = $store->settingsScope();
        $radius = FoodSettings::decimalOrNull('food.delivery_radius_km', $scope);
        $base = FoodSettings::decimalOrNull('food.delivery_fee_base', $scope);

        if ($radius === null && $base === null) {
            return [(float) $store->delivery_fee_flat, null];
        }

        if ($address->lat === null || $address->lng === null || $store->lat === null || $store->lng === null) {
            throw new \RuntimeException(SiteText::get('food.checkout.no_map'));
        }

        $km = $this->dispatch->haversineKm((float) $store->lat, (float) $store->lng, (float) $address->lat, (float) $address->lng);

        if ($radius !== null && $km > $radius) {
            throw new \RuntimeException(SiteText::get('food.checkout.too_far', ['km' => $this->km($radius), 'distance' => $this->km($km)]));
        }

        if ($base === null) {
            return [(float) $store->delivery_fee_flat, $km];
        }

        $includedKm = (float) (FoodSettings::get('food.delivery_fee_base_km', $scope) ?? 0);
        $perKm = (float) (FoodSettings::get('food.delivery_fee_per_km', $scope) ?? 0);
        $extraKm = max(0, (int) ceil(round($km - $includedKm, 2)));

        return [round($base + $extraKm * $perKm, 2), $km];
    }

    /** GST rate under section 9(5); null when not set (no GST on food orders). */
    public function gstPercent(Store $store): ?float
    {
        $rate = FoodSettings::decimalOrNull('food.gst_percent', $store->settingsScope());

        return $rate !== null && $rate > 0 ? $rate : null;
    }

    private function km(float $km): string
    {
        return rtrim(rtrim(number_format($km, 1), '0'), '.');
    }
}
