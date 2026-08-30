<?php

namespace App\Services\Customer;

use App\Models\Service;
use App\Models\ServiceCartItem;
use App\Models\User;
use App\Support\Modules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The customer storefront's services cart.
 *
 * Deliberately NOT App\Services\CartService — that one is the Marketplace
 * (Phase 24) product cart: `store_id`-scoped, product/variant/add-on lines,
 * a different domain entirely. Services have no store, they have option
 * groups, a per-job address + schedule, dispatch, OTP.
 *
 * ── Pricing here is a display estimate, never the charge ──────────────────
 * `unit_price_estimate` is snapshotted from CatalogPresenter (the same
 * number the service detail page and the booking wizard already show as an
 * "estimate"). The authoritative price — the Phase-D franchise / discount /
 * flash-sale cascade — is computed by CreateBookingAction::createWithin
 * Transaction() at checkout, once per bundle child, and nowhere else. This
 * class does no price arithmetic that any charge depends on. Same boundary
 * App\Services\CartService and ConfiguresServiceOptions state for themselves.
 *
 * ── Visits ───────────────────────────────────────────────────────────────
 * groupedForUser() buckets lines by SUBCATEGORY (falling back to category),
 * which is the customer-facing "one professional, one visit" grouping — an
 * AC technician handling fridge + washing machine + window AC is one
 * subcategory; a leaky tap is another. The backend's actual same-provider
 * consolidation is BundleConsolidationJob's job (guarded to the same
 * subcategory rule in Part E); this grouping sets the customer's
 * expectation to match it.
 */
class ServiceCartService
{
    /**
     * Add a service line, or merge into an identical existing one (same
     * service + same option selection + same preferred slot) by bumping its
     * quantity.
     *
     * @param  array  $options  {group_id: option_id | [option_ids]} — advisory, see the class docblock
     *
     * @throws \RuntimeException  when the service is not a bookable, active Service-vertical service
     */
    public function add(
        User $user,
        Service $service,
        array $options = [],
        ?Carbon $scheduledAt = null,
        string $note = '',
        int $quantity = 1,
    ): ServiceCartItem {
        if ($quantity < 1) {
            throw new \RuntimeException('Quantity must be at least 1.');
        }

        $this->assertBookable($service);

        $options = $this->normaliseOptions($options);
        $note = trim($note);

        $existing = ServiceCartItem::query()
            ->where('user_id', $user->id)
            ->where('service_id', $service->id)
            ->where('scheduled_at', $scheduledAt)
            ->get()
            ->first(fn (ServiceCartItem $item) => $this->normaliseOptions($item->selected_options ?? []) === $options);

        if ($existing) {
            $existing->quantity += $quantity;
            if ($note !== '') {
                $existing->customer_note = $note;
            }
            $existing->save();

            return $existing;
        }

        return ServiceCartItem::create([
            'user_id' => $user->id,
            'service_id' => $service->id,
            'category_id' => $service->category_id,
            'subcategory_id' => $service->subcategory_id,
            'quantity' => $quantity,
            'selected_options' => $options ?: null,
            'scheduled_at' => $scheduledAt,
            'customer_note' => $note ?: null,
            'unit_price_estimate' => $this->estimateFor($service),
        ]);
    }

    public function updateQuantity(ServiceCartItem $item, int $quantity): void
    {
        if ($quantity < 1) {
            $item->delete();

            return;
        }

        $item->update(['quantity' => $quantity]);
    }

    public function updateSchedule(ServiceCartItem $item, ?Carbon $scheduledAt): void
    {
        $item->update(['scheduled_at' => $scheduledAt]);
    }

    public function updateOptions(ServiceCartItem $item, array $options): void
    {
        $normalised = $this->normaliseOptions($options);
        $item->update(['selected_options' => $normalised ?: null]);
    }

    public function updateNote(ServiceCartItem $item, string $note): void
    {
        $note = trim($note);
        $item->update(['customer_note' => $note !== '' ? $note : null]);
    }

    public function remove(ServiceCartItem $item): void
    {
        $item->delete();
    }

    public function clear(User $user): void
    {
        ServiceCartItem::where('user_id', $user->id)->delete();
    }

    /**
     * Every line for this customer, oldest first, with the relationships the
     * cart and checkout templates read.
     *
     * @return Collection<int, ServiceCartItem>
     */
    public function itemsFor(User $user): Collection
    {
        return ServiceCartItem::query()
            ->where('user_id', $user->id)
            ->with(['service.category', 'service.subcategory'])
            ->orderBy('id')
            ->get();
    }

    /**
     * The cart bucketed into "visits". One entry per subcategory (or per
     * category for lines with no subcategory), in the order the customer
     * first added something from that bucket.
     *
     * @return Collection<int, array{key:string, label:string, items:Collection<int, ServiceCartItem>, item_count:int}>
     */
    public function groupedForUser(User $user): Collection
    {
        return $this->itemsFor($user)
            ->groupBy(fn (ServiceCartItem $item) => $item->visitGroupKey())
            ->map(fn (Collection $items) => [
                'key' => $items->first()->visitGroupKey(),
                'label' => $items->first()->subcategory?->name
                    ?? $items->first()->category?->name
                    ?? 'Other services',
                'items' => $items->values(),
                'item_count' => (int) $items->sum('quantity'),
            ])
            ->values();
    }

    /** Total line quantity across the cart — the number for the topbar badge. */
    public function lineCount(User $user): int
    {
        return (int) ServiceCartItem::where('user_id', $user->id)->sum('quantity');
    }

    /** Advisory only — the sum of per-line estimates. NOT what checkout charges. */
    public function estimateTotal(User $user): float
    {
        return (float) $this->itemsFor($user)->sum(
            fn (ServiceCartItem $item) => (float) ($item->unit_price_estimate ?? 0) * $item->quantity
        );
    }

    // ------------------------------------------------------------------

    private function assertBookable(Service $service): void
    {
        $service->loadMissing('category');

        $ok = $service->is_active
            && $service->category
            && $service->category->is_active
            && $service->category->module === Modules::SERVICE;

        if (! $ok) {
            throw new \RuntimeException('That service cannot be added to the cart.');
        }
    }

    /** The same estimate CatalogPresenter shows on the detail page / wizard. */
    private function estimateFor(Service $service): ?float
    {
        $price = app(CatalogPresenter::class)->card($service)['price'] ?? null;

        return $price === null ? null : (float) $price;
    }

    /**
     * Canonical form of an option selection so two lines that chose the same
     * options in a different order still merge: sorted by group id, each
     * value an ascending list of ints.
     */
    private function normaliseOptions(array $options): array
    {
        $out = [];

        foreach ($options as $groupId => $value) {
            $ids = collect((array) $value)
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();

            if ($ids !== []) {
                $out[(int) $groupId] = $ids;
            }
        }

        ksort($out);

        return $out;
    }
}
