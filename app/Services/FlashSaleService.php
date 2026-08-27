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
     * THE authoritative "what does this service cost right now, for this
     * viewer" answer — the whole cascade this class's own docblock already
     * describes, finally callable as one thing:
     *
     *     Service::resolvePrice($franchiseId)   franchise override -> discount_price -> base_price
     *     then this class's sale layer          an active, scope-covering, not-sold-out sale wins outright
     *
     * Nothing new is computed here. Both layers already existed and are
     * called in the order they were already documented in; this method only
     * removes the need for every caller to compose them by hand. It was
     * extracted (Phase D) for exactly the reason Service::resolvePrice()
     * itself was extracted from Livewire\Bookings\Index::loadPrice(): the
     * composition existed in CatalogPresenter::cards() and NOWHERE else, so
     * App\Http\Controllers\API\BookingController quoted layer one alone
     * and a customer could be shown a flash-sale price then charged the
     * undiscounted one. See CreateBookingAction::resolveAuthoritativePrice().
     *
     * `sale` is the same array priceFor() returns (null when none applies),
     * kept so a caller that must RECORD the usage — redeem() — still has the
     * sale in hand without looking it up a second time.
     *
     * @return array{price: float, resolved_price: float, sale: ?array}
     */
    public function effectivePriceFor(Service $service, ?int $franchiseId, array $viewerScope = []): array
    {
        return $this->effectivePricesFor(collect([$service]), $franchiseId, $viewerScope)[$service->id];
    }

    /**
     * effectivePriceFor() for a whole page of services, in the same fixed
     * number of queries priceForMany() already answers a grid in — the
     * batched form exists for the same N+1 reason BadgeService::
     * badgesForMany() and priceForMany() do, and the single-service method
     * above delegates here so the cascade has exactly ONE implementation.
     *
     * @param  \Illuminate\Support\Collection<int, Service>  $services
     * @return array<int, array{price: float, resolved_price: float, sale: ?array}> keyed by service id
     */
    public function effectivePricesFor(\Illuminate\Support\Collection $services, ?int $franchiseId, array $viewerScope = []): array
    {
        if ($services->isEmpty()) {
            return [];
        }

        $resolved = $services->mapWithKeys(fn (Service $s) => [$s->id => $s->resolvePrice($franchiseId)])->all();
        $sales = $this->priceForMany($services, $resolved, $viewerScope);

        $out = [];

        foreach ($services as $service) {
            $sale = $sales[$service->id] ?? null;

            $out[$service->id] = [
                'price' => $sale ? (float) $sale['final_price'] : (float) $resolved[$service->id],
                'resolved_price' => (float) $resolved[$service->id],
                'sale' => $sale,
            ];
        }

        return $out;
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
        return $this->priceForMany(collect([$service]), [$service->id => $originalPrice], $viewerScope)[$service->id] ?? null;
    }

    /**
     * The same answer as priceFor(), for a whole page of services at once.
     *
     * Added for the customer catalog (Phase C), for the same reason
     * BadgeService::badgesForMany() was: a catalog grid asking priceFor()
     * per card is one `whereHas` query per card on the hottest read path in
     * the customer app. priceFor() now delegates here, so the scope /
     * sold-out / precedence rules still have exactly ONE implementation.
     *
     * remainingQuantity() is resolved once per SALE rather than once per
     * service, since many services in one grid typically share a sale.
     *
     * @param  \Illuminate\Support\Collection<int, Service>  $services
     * @param  array<int, float>  $originalPrices  service id => the price the existing cascade already resolved for this viewer
     * @return array<int, array|null> keyed by service id; null where no sale applies
     */
    public function priceForMany(\Illuminate\Support\Collection $services, array $originalPrices, array $viewerScope = []): array
    {
        if ($services->isEmpty()) {
            return [];
        }

        $authz = app(AuthorizationService::class);
        $serviceIds = $services->pluck('id');

        $sales = FlashSale::currentlyActive()
            ->whereHas('targets', fn ($q) => $q->whereIn('service_id', $serviceIds))
            ->with(['targets' => fn ($q) => $q->whereIn('service_id', $serviceIds)])
            ->get()
            ->filter(fn (FlashSale $s) => $authz->scopeCovers($s->scope_type, $s->scope_id, $viewerScope));

        // One count query per distinct sale, not per service.
        $remaining = $sales->mapWithKeys(fn (FlashSale $s) => [$s->id => $this->remainingQuantity($s)]);
        $sales = $sales->filter(fn (FlashSale $s) => $remaining[$s->id] !== 0);

        $result = [];

        foreach ($services as $service) {
            // Preserves priceFor()'s original ordering semantics: the first
            // qualifying sale in FlashSale::currentlyActive()'s own order
            // wins, exactly as ->first() did before.
            $sale = $sales->first(
                fn (FlashSale $s) => $s->targets->contains(fn (FlashSaleTarget $t) => (int) $t->service_id === (int) $service->id)
            );

            if (! $sale) {
                $result[$service->id] = null;

                continue;
            }

            $originalPrice = (float) ($originalPrices[$service->id] ?? 0.0);

            $result[$service->id] = [
                'flash_sale_id' => $sale->id,
                'original_price' => $originalPrice,
                'final_price' => $sale->computeFinalPrice($originalPrice),
                'discount_type' => $sale->discount_type,
                'discount_value' => (float) $sale->discount_value,
                'remaining_quantity' => $remaining[$sale->id],
            ];
        }

        return $result;
    }

    /**
     * Every service id that currently has an offer this viewer can actually
     * get — the id set behind the customer app's Offers screen (Phase C).
     *
     * Applies the identical three conditions priceForMany() does (currently
     * active, scope-covering, not sold out) so the Offers list and the price
     * shown on each of its cards can never disagree about whether a sale
     * applies. Returns an empty collection when nothing is on offer, which
     * the caller renders as an honest empty state rather than falling back
     * to showing full-price services under an "Offers" heading.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    public function activeServiceIdsFor(array $viewerScope = []): \Illuminate\Support\Collection
    {
        $authz = app(AuthorizationService::class);

        return FlashSale::currentlyActive()
            ->with('targets')
            ->get()
            ->filter(fn (FlashSale $s) => $authz->scopeCovers($s->scope_type, $s->scope_id, $viewerScope))
            ->filter(fn (FlashSale $s) => $this->remainingQuantity($s) !== 0)
            ->flatMap(fn (FlashSale $s) => $s->targets->pluck('service_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
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
