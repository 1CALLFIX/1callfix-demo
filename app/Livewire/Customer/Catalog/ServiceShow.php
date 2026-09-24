<?php

namespace App\Livewire\Customer\Catalog;

use App\Livewire\Customer\Concerns\ResolvesCatalogContext;
use App\Models\Service;
use App\Models\ServiceCartItem;
use App\Models\ServiceOption;
use App\Models\ServiceOptionGroup;
use App\Services\Customer\ServiceCartService;
use App\Services\Customer\ServiceRatingSummary;
use App\Services\DispatchService;
use App\Services\TimezoneResolver;
use App\Support\BookingSchedule;
use App\Support\Modules;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One service in full: price, rating, description, option configuration, live
 * availability, reviews (Phase C).
 *
 * ── Options: displayed and priced, but NOT yet bookable ───────────────────
 * `service_option_groups` / `service_options` are real, admin-managed tables
 * (Livewire\Services\Manage's options modal writes them) and this screen
 * renders them faithfully, including each option's real `price_delta`.
 *
 * What does not exist yet is the other end: a full-repository read before
 * building this confirmed that NOTHING in `app/` ever writes a
 * `booking_options` row. `CreateBookingAction::execute()` takes no options
 * argument and computes `price_quoted` from `price_quoted ?? base_price`
 * alone — the `BookingOption` model and its table exist with no writer.
 * (CUSTOMER_WEBAPP_READINESS_MATRIX.md row 17 describes options as already
 * flowing through `CreateBookingAction`; that is not what the code does.
 * Recorded as a Phase D dependency rather than worked around.)
 *
 * So the total this screen shows is labelled for what it is: an estimate
 * built from real prices, confirmed when booking opens. It is NOT presented
 * as a committed quote, and no attempt is made here to write a booking or
 * its options.
 *
 * ── The estimate is computed on the server ────────────────────────────────
 * Every price on this page is computed in PHP, in this component, from
 * database values. Nothing is added up in JavaScript, and no client-supplied
 * price is ever read back — selections arrive as option IDs, are validated
 * against this service's own option groups, and the deltas are re-read from
 * the database. A tampered payload can at most select an option that really
 * belongs to this service at the price the database really holds.
 */
class ServiceShow extends Component
{
    use ResolvesCatalogContext;

    /** #[Locked]: Livewire round-trips public properties through the browser, and this must not be repointable at another service. */
    #[Locked]
    public int $serviceId;

    /**
     * group id => selected option id (single-choice) or array of ids
     * (allow_multiple). Written only through selectOption()/toggleOption(),
     * both of which validate against this service's own groups.
     */
    public array $selected = [];

    /** @var Collection<int, ServiceOptionGroup>|null per-request memo for groups(); private so Livewire never serialises it */
    private ?Collection $groupCache = null;

    /** "Add to cart" — a preferred slot ('Y-m-d\TH:i' local, empty = ASAP) and an optional note. Estimate/expectation only; checkout re-prices. */
    public string $preferredAt = '';

    public string $customerNote = '';

    /** Inline confirmation after a successful add, cleared on the next option change. */
    public string $cartNotice = '';

    /**
     * The stepper next to the price: how many of this service the cart line
     * should hold. Starts at 1, or at the existing line's quantity. 0 is only
     * reachable while editing an existing line, and means "remove it".
     */
    public int $quantity = 1;

    /**
     * The customer's cart line for this service that the stepper edits, or
     * null when there is none yet. #[Locked] and re-scoped to the user on
     * every use, so a tampered id can never touch someone else's cart.
     */
    #[Locked]
    public ?int $cartItemId = null;

    private const REVIEW_LIMIT = 5;
    private const RELATED_LIMIT = 4;

    public function mount(Service $service): void
    {
        // Binding resolves the id; it knows nothing about visibility. Apply
        // exactly the catalog's own rule, so an inactive service — or one
        // whose category is inactive or belongs to another vertical — is a
        // 404 here just as it is absent from GET /api/services.
        abort_unless(
            $service->is_active
                && $service->category
                && $service->category->is_active
                && $service->category->module === Modules::SERVICE,
            404,
        );

        $this->serviceId = $service->id;
        $this->preselectRequiredGroups($service);
        $this->loadExistingCartLine();
    }

    public function incrementQuantity(): void
    {
        $this->resetErrorBag('cart');

        if ($this->quantity >= ServiceCartService::MAX_QUANTITY) {
            $this->addError('cart', ServiceCartService::maxQuantityMessage());

            return;
        }

        $this->quantity++;
    }

    /** Floors at 1 for a new line; at 0 (= remove) when editing an existing one. */
    public function decrementQuantity(): void
    {
        $this->resetErrorBag('cart');
        $this->quantity = max($this->cartItemId ? 0 : 1, $this->quantity - 1);
    }

    /** Single-choice group: one option replaces any previous choice. */
    public function selectOption(int $groupId, int $optionId): void
    {
        $group = $this->groups()->firstWhere('id', $groupId);

        if (! $group || $group->allow_multiple || ! $group->options->contains('id', $optionId)) {
            return;
        }

        $this->selected[$groupId] = $optionId;
    }

    /** Multi-choice group: toggle one option in or out of the set. */
    public function toggleOption(int $groupId, int $optionId): void
    {
        $group = $this->groups()->firstWhere('id', $groupId);

        if (! $group || ! $group->allow_multiple || ! $group->options->contains('id', $optionId)) {
            return;
        }

        $current = collect((array) ($this->selected[$groupId] ?? []))->map(fn ($id) => (int) $id);

        $this->selected[$groupId] = $current->contains($optionId)
            ? $current->reject(fn ($id) => $id === $optionId)->values()->all()
            : $current->push($optionId)->values()->all();
    }

    /**
     * Add this service, with the current option selection and an optional
     * preferred slot, to the customer's services cart. The option selection
     * and the estimate are advisory — ServiceCartService and the checkout
     * bundle action re-derive the authoritative charge. Requires a login
     * (the cart is per-user, DB-backed); a guest is sent to sign in and back.
     *
     * With an existing line (cartItemId) this writes the stepper's quantity
     * and the form onto that line instead — 0 removes it. Either way the
     * customer stays on this page; the topbar badge follows via
     * 'cart-updated'.
     */
    public function addToCart(ServiceCartService $cart): void
    {
        $this->cartNotice = '';
        $this->resetErrorBag();

        if (! auth()->check()) {
            $this->redirectRoute('customer.login', ['intended' => route('customer.services.show', $this->serviceId)]);

            return;
        }

        $existing = $this->cartItemId
            ? ServiceCartItem::where('user_id', auth()->id())->find($this->cartItemId)
            : null;

        if ($existing && $this->quantity < 1) {
            $cart->remove($existing);
            $this->cartItemId = null;
            $this->quantity = 1;
            $this->cartNotice = 'Removed from your cart.';
            $this->dispatch('cart-updated');

            return;
        }

        if ($this->missingRequiredGroups()->isNotEmpty()) {
            $this->addError('cart', 'Choose every required option before adding to the cart.');

            return;
        }

        if (($msg = BookingSchedule::validate($this->preferredAt)) !== null) {
            $this->addError('cart', $msg);

            return;
        }

        $service = Service::findOrFail($this->serviceId);

        try {
            if ($existing) {
                $cart->updateQuantity($existing, $this->quantity);
                $cart->updateOptions($existing, $this->selected);
                $cart->updateSchedule($existing, BookingSchedule::parse($this->preferredAt));
                $cart->updateNote($existing, $this->customerNote);
                $this->cartNotice = 'Cart updated.';
            } else {
                $item = $cart->add(
                    auth()->user(),
                    $service,
                    $this->selected,
                    BookingSchedule::parse($this->preferredAt),
                    $this->customerNote,
                    max(1, $this->quantity),
                );
                // add() may have merged into an identical line; the stepper
                // now tracks whatever line holds these units.
                $this->cartItemId = $item->id;
                $this->quantity = $item->quantity;
                $this->cartNotice = 'Added to your cart.';
            }
        } catch (\RuntimeException $e) {
            $this->addError('cart', $e->getMessage());

            return;
        }

        $this->dispatch('cart-updated');
    }

    public function render()
    {
        $service = Service::with(['category', 'subcategory'])->findOrFail($this->serviceId);
        $ratings = app(ServiceRatingSummary::class);

        $card = $this->presenter()->card($service);
        $selectedOptions = $this->selectedOptions();
        $estimatedTotal = $card['price'] + $this->optionsTotal($selectedOptions);

        return view('livewire.customer.catalog.service-show', [
            'service' => $service,
            'card' => $card,
            'groups' => $this->groups(),
            'selectedOptions' => $selectedOptions,
            'optionsTotal' => $this->optionsTotal($selectedOptions),
            'estimatedTotal' => $estimatedTotal,
            'quantitySubtotal' => $estimatedTotal * $this->quantity,
            'missingRequiredGroups' => $this->missingRequiredGroups(),
            'reviews' => $ratings->recentFor($service->id, self::REVIEW_LIMIT),
            'bookingCount' => $this->catalog()->bookingCountFor($service->id, $this->location()->franchiseId()),
            'availableProviderCount' => $this->availableProviderCount($service),
            'activeZone' => $this->location()->zone(),
            'related' => $this->related($service),
            'currencySymbol' => $this->presenter()->currencySymbol(),
        ])->layout('components.layouts.customer', [
            'title' => $service->name,
            'metaDescription' => $service->description
                ? \Illuminate\Support\Str::limit(strip_tags($service->description), 150)
                : $service->name.' — book a verified professional.',
        ]);
    }

    /**
     * This service's active option groups with their active options, ordered
     * as the admin arranged them. Memoised on the INSTANCE (a private
     * property, so Livewire never serialises it into the page payload) —
     * deliberately not a `static` local, which would be shared across every
     * instance in the process and hand a second component the first one's
     * option groups.
     *
     * @return Collection<int, ServiceOptionGroup>
     */
    private function groups(): Collection
    {
        return $this->groupCache ??= ServiceOptionGroup::query()
            ->where('service_id', $this->serviceId)
            ->with(['options' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('id')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            // A group whose every option was deactivated is nothing to
            // choose from; showing an empty fieldset would look broken.
            ->filter(fn (ServiceOptionGroup $g) => $g->options->isNotEmpty())
            ->values();
    }

    /**
     * Required single-choice groups start on their first option, so the
     * estimate on screen is a complete, real configuration from the first
     * paint rather than a base price the customer cannot actually buy.
     * Required multi-choice groups are left empty on purpose — "pick at
     * least one of these" is a genuine choice and pre-ticking one would
     * silently make it for the customer.
     */
    private function preselectRequiredGroups(Service $service): void
    {
        foreach ($this->groups() as $group) {
            if ($group->is_required && ! $group->allow_multiple) {
                $this->selected[$group->id] = $group->options->first()->id;
            }
        }
    }

    /**
     * If the customer already has this service in their cart, the stepper and
     * form open on that line (the most recently added one, if several) rather
     * than on 1 — so "Add" never silently doubles what is already there.
     * Stored options are re-filtered through this service's live groups, the
     * same boundary selectOption()/toggleOption() enforce.
     */
    private function loadExistingCartLine(): void
    {
        if (! auth()->check()) {
            return;
        }

        $line = ServiceCartItem::where('user_id', auth()->id())
            ->where('service_id', $this->serviceId)
            ->latest('id')
            ->first();

        if (! $line) {
            return;
        }

        $this->cartItemId = $line->id;
        $this->quantity = $line->quantity;
        $this->preferredAt = app(TimezoneResolver::class)->toLocalInput($line->scheduled_at) ?? '';
        $this->customerNote = (string) $line->customer_note;

        $groups = $this->groups()->keyBy('id');
        foreach ($line->selected_options ?? [] as $groupId => $ids) {
            $group = $groups->get((int) $groupId);
            if (! $group) {
                continue;
            }

            $valid = collect((array) $ids)->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $group->options->contains('id', $id))
                ->values()->all();

            if ($valid === []) {
                continue;
            }

            $this->selected[$group->id] = $group->allow_multiple ? $valid : $valid[0];
        }
    }

    /**
     * The real ServiceOption rows behind the current selection, re-read from
     * the database. This is the validation boundary: an id that is not an
     * active option of one of THIS service's groups is discarded, so nothing
     * a tampered payload contains can reach the price.
     *
     * @return Collection<int, ServiceOption>
     */
    private function selectedOptions(): Collection
    {
        $groups = $this->groups()->keyBy('id');

        return collect($this->selected)
            ->flatMap(function ($value, $groupId) use ($groups) {
                $group = $groups->get((int) $groupId);

                if (! $group) {
                    return [];
                }

                return collect((array) $value)
                    ->map(fn ($id) => $group->options->firstWhere('id', (int) $id))
                    ->filter();
            })
            ->values();
    }

    /** @param  Collection<int, ServiceOption>  $options */
    private function optionsTotal(Collection $options): float
    {
        return (float) $options->sum(fn (ServiceOption $option) => (float) $option->price_delta);
    }

    /**
     * Required groups the customer has not answered yet — used to explain
     * why the estimate is still incomplete. It gates nothing, because
     * booking itself is Phase D; when the wizard lands this is the list it
     * must block on.
     *
     * @return Collection<int, ServiceOptionGroup>
     */
    private function missingRequiredGroups(): Collection
    {
        return $this->groups()->filter(function (ServiceOptionGroup $group) {
            if (! $group->is_required) {
                return false;
            }

            return empty($this->selected[$group->id] ?? null);
        })->values();
    }

    /**
     * How many providers could actually take this job in the customer's
     * chosen zone right now — through DispatchService::nearbyForService(),
     * the existing read-only "browse nearby providers" call that
     * `GET /api/providers/nearby` already uses. No second eligibility rule
     * is written here.
     *
     * null (rather than 0) when the question cannot be asked honestly: no
     * zone chosen, or the zone has no centre coordinate recorded. The
     * template renders nothing at all in that case — "0 available" and
     * "we don't know" are different statements and must not look alike.
     *
     * This is a browse-time indicator, not a booking guarantee: whether a
     * specific job gets picked up is decided at dispatch time by the same
     * service, against the customer's real address rather than the zone's
     * centre.
     */
    private function availableProviderCount(Service $service): ?int
    {
        $zone = $this->location()->zone();

        if (! $zone || $zone->center_lat === null || $zone->center_lng === null) {
            return null;
        }

        return app(DispatchService::class)->nearbyForService(
            $service,
            $zone,
            (float) $zone->center_lat,
            (float) $zone->center_lng,
        )->count();
    }

    /** Other services in the same category — the natural "customers also looked at" without inventing a recommendation engine. */
    private function related(Service $service): Collection
    {
        return $this->cardsFrom(
            $this->catalog()->services(['category_id' => $service->category_id])->whereKeyNot($service->id),
            self::RELATED_LIMIT,
        );
    }
}
