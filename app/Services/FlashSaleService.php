<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\FlashSale;
use App\Models\FlashSaleRedemption;
use App\Models\FlashSaleTarget;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Flash Sale Engine. Integrates with the EXISTING pricing cascade
 * (Service::base_price/discount_price -> FranchiseServicePricing override)
 * as one more layer, not a parallel pricing system — see priceFor()'s own
 * docblock for exactly where it sits. Reuses Coupon's discount_type/value/
 * max_discount vocabulary rather than inventing a second one.
 */
class FlashSaleService
{
    /**
     * Legal admin-driven transitions. 'live'/'completed' also happen
     * implicitly via FlashSale::isCurrentlyActive()'s own time check
     * regardless of these — this map only governs explicit STATUS COLUMN
     * changes (audit/reporting), never gates the actual discount
     * application, which never trusts status in isolation.
     */
    private const TRANSITIONS = [
        'draft' => ['scheduled', 'cancelled'],
        'scheduled' => ['live', 'paused', 'cancelled'],
        'live' => ['paused', 'completed', 'cancelled'],
        'paused' => ['scheduled', 'live', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    private function transition(FlashSale $sale, string $to): FlashSale
    {
        $allowed = self::TRANSITIONS[$sale->status] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw new \RuntimeException("Cannot move a flash sale from '{$sale->status}' to '{$to}'.");
        }

        $sale->status = $to;
        $sale->save();

        return $sale->fresh();
    }

    public function schedule(FlashSale $sale, Carbon $startsAt, Carbon $endsAt): FlashSale
    {
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw new \RuntimeException('The sale must end after it starts.');
        }

        $sale->starts_at = $startsAt;
        $sale->ends_at = $endsAt;
        $sale->save();

        return $this->transition($sale->fresh(), 'scheduled');
    }

    /** Explicit "start now" override — forces starts_at to the present if it's still in the future, so the sale is genuinely live immediately rather than merely relabeled. */
    public function goLive(FlashSale $sale): FlashSale
    {
        if (! $sale->ends_at || $sale->ends_at->isPast()) {
            throw new \RuntimeException('Cannot go live without a real, still-open end time.');
        }

        if (! $sale->starts_at || $sale->starts_at->isFuture()) {
            $sale->starts_at = now();
            $sale->save();
        }

        return $this->transition($sale->fresh(), 'live');
    }

    public function pause(FlashSale $sale): FlashSale
    {
        return $this->transition($sale, 'paused');
    }

    /** Resumes into 'live' or 'scheduled' depending on whether the window has actually started — status stays honest rather than always bouncing back to 'live'. */
    public function resume(FlashSale $sale): FlashSale
    {
        $target = ($sale->starts_at && $sale->starts_at->isPast()) ? 'live' : 'scheduled';

        return $this->transition($sale, $target);
    }

    public function cancel(FlashSale $sale): FlashSale
    {
        return $this->transition($sale, 'cancelled');
    }

    public function complete(FlashSale $sale): FlashSale
    {
        return $this->transition($sale, 'completed');
    }

    /**
     * @throws \RuntimeException on a duplicate target (same service already
     *         on this sale) or an overlapping ACTIVE sale already targeting
     *         this service at an overlapping scope -- "prevent duplicate
     *         discount" from the mission's own explicit requirement,
     *         checked at assignment time rather than left to whichever
     *         sale a read happens to pick first.
     */
    public function addTarget(FlashSale $sale, Service $service): FlashSaleTarget
    {
        if (FlashSaleTarget::where('flash_sale_id', $sale->id)->where('service_id', $service->id)->exists()) {
            throw new \RuntimeException("Service [{$service->name}] is already a target of this sale.");
        }

        $overlapping = FlashSale::currentlyActive()
            ->where('id', '!=', $sale->id)
            ->where('scope_type', $sale->scope_type)
            ->where('scope_id', $sale->scope_id)
            ->whereHas('targets', fn ($q) => $q->where('service_id', $service->id))
            ->exists();

        if ($overlapping) {
            throw new \RuntimeException("Service [{$service->name}] already has an active flash sale at this exact scope.");
        }

        return FlashSaleTarget::create(['flash_sale_id' => $sale->id, 'service_id' => $service->id]);
    }

    public function removeTarget(FlashSaleTarget $target): void
    {
        $target->delete();
    }

    /**
     * The sale-price layer of the EXISTING pricing cascade: base_price/
     * discount_price -> FranchiseServicePricing's per-franchise override ->
     * (this) an active flash sale, which takes precedence when present --
     * a flash sale is inherently the most specific, most time-limited
     * layer, so it wins outright rather than stacking with the franchise
     * override (default-to-no-stacking, per KNOWN_RISKS_AND_DECISIONS.md
     * item 12 -- not invented here, a genuine open policy question for
     * ANY future stacking, resolved safely by defaulting closed).
     *
     * Returns null if no currently-active, scope-covering, not-sold-out
     * sale targets this service — callers fall through to the existing
     * cascade unchanged.
     *
     * @param  array  $viewerScope  same shape as everywhere else this
     *         session (['zone_id'=>.., 'franchise_id'=>.., ...]).
     */
    public function priceFor(Service $service, float $originalPrice, array $viewerScope = []): ?array
    {
        $authz = app(AuthorizationService::class);

        $sale = FlashSale::currentlyActive()
            ->whereHas('targets', fn ($q) => $q->where('service_id', $service->id))
            ->get()
            ->first(fn (FlashSale $s) => $authz->scopeCovers($s->scope_type, $s->scope_id, $viewerScope) && $this->remainingQuantity($s) !== 0);

        if (! $sale) {
            return null;
        }

        return [
            'flash_sale_id' => $sale->id,
            'original_price' => $originalPrice,
            'final_price' => $sale->computeFinalPrice($originalPrice),
            'discount_type' => $sale->discount_type,
            'discount_value' => (float) $sale->discount_value,
            'remaining_quantity' => $this->remainingQuantity($sale),
        ];
    }

    /** null = unlimited. */
    public function remainingQuantity(FlashSale $sale): ?int
    {
        if ($sale->total_quantity_limit === null) {
            return null;
        }

        return max(0, $sale->total_quantity_limit - FlashSaleRedemption::where('flash_sale_id', $sale->id)->count());
    }

    /**
     * Records that a booking actually used this sale's price — the ONLY
     * place quantity/per-customer limits are enforced against real
     * committed usage, concurrency-safe via a row lock on the sale itself
     * (the same DB::transaction()+lockForUpdate() convention every
     * booking-mutating Action in this codebase already uses, e.g.
     * AcceptBookingAction's own offer-race guard) so two simultaneous
     * redemptions can never both slip through a quantity limit of 1.
     *
     * @throws \RuntimeException if the sale is no longer active, sold out,
     *         or this customer already hit their per-sale limit.
     */
    public function redeem(FlashSale $sale, Service $service, User $user, float $originalPrice, ?Booking $booking = null): FlashSaleRedemption
    {
        return DB::transaction(function () use ($sale, $service, $user, $originalPrice, $booking) {
            $locked = FlashSale::lockForUpdate()->findOrFail($sale->id);

            if (! $locked->isCurrentlyActive()) {
                throw new \RuntimeException('This flash sale is no longer active.');
            }

            if ($locked->total_quantity_limit !== null) {
                $used = FlashSaleRedemption::where('flash_sale_id', $locked->id)->count();
                if ($used >= $locked->total_quantity_limit) {
                    throw new \RuntimeException('This flash sale is sold out.');
                }
            }

            if ($locked->per_customer_limit !== null) {
                $usedByCustomer = FlashSaleRedemption::where('flash_sale_id', $locked->id)->where('user_id', $user->id)->count();
                if ($usedByCustomer >= $locked->per_customer_limit) {
                    throw new \RuntimeException('You have already used this flash sale the maximum number of times.');
                }
            }

            $finalPrice = $locked->computeFinalPrice($originalPrice);

            return FlashSaleRedemption::create([
                'flash_sale_id' => $locked->id,
                'service_id' => $service->id,
                'user_id' => $user->id,
                'booking_id' => $booking?->id,
                'original_price' => $originalPrice,
                'final_price' => $finalPrice,
                'discount_applied' => round($originalPrice - $finalPrice, 2),
            ]);
        });
    }
}
