<?php

namespace App\Livewire\Customer\Food;

use App\Exceptions\FoodCartConflict;
use App\Livewire\Customer\Food\Concerns\InteractsWithFoodCity;
use App\Models\AddOnGroup;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Models\Store;
use App\Services\Food\FoodCart;
use App\Services\Food\FoodCheckout;
use App\Support\SiteText;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Food F3 — /{city}/food/{restaurant}: the menu. Sections from the restaurant's own categories, the veg / egg /
 * non-veg mark on every dish, a "Veg only" switch, search. Sold-out-today dishes are not shown. A dish with a
 * portion (Half / Full) or add-on groups opens a sheet that follows each group's rule ("Pick 1", "Up to 3") and shows
 * the price as you choose. One restaurant per food cart: a dish from another restaurant asks before starting a new
 * cart. A closed restaurant shows its menu with when it opens, and nothing can be added.
 */
class RestaurantShow extends Component
{
    use InteractsWithFoodCity;

    public Store $store;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'veg')]
    public bool $vegOnly = false;

    public string $message = '';

    /** The dish whose choices sheet is open. */
    public ?int $customizing = null;

    public ?int $variantId = null;

    /** group id => add-on id, for "pick 1" groups. */
    public array $single = [];

    /** group id => [add-on ids], for groups that allow more than one. */
    public array $multi = [];

    public string $sheetError = '';

    /** Another restaurant's dishes are in the cart: [name of that restaurant, the add we are waiting to make]. */
    public ?array $conflict = null;

    public function mount(string $city, string $restaurantSlug): void
    {
        if (! $this->enterFoodCity($city)) {
            return;
        }
        $this->store = self::publicRestaurantsIn($this->foodCity)->where('slug', $restaurantSlug)->firstOrFail();
        abort_if($this->store->missingFssai(), 404);
    }

    private function cart(): FoodCart
    {
        return app(FoodCart::class);
    }

    private function dish(int $productId): ?Product
    {
        return Product::where('store_id', $this->store->id)->where('is_active', true)->where('is_approved', true)->find($productId);
    }

    /** The menu's "Add" / "+": a plain dish goes straight in; a dish with choices opens its sheet. */
    public function add(int $productId): void
    {
        $this->message = '';
        $dish = $this->dish($productId);
        if (! $dish) {
            $this->message = SiteText::get('food.cart.dish_gone');

            return;
        }
        if ($dish->hasChoices() || $this->groupsFor($dish)->isNotEmpty()) {
            $this->openSheet($dish);

            return;
        }
        $this->tryAdd(['product' => $dish->id, 'variant' => null, 'addOns' => []]);
    }

    public function removeOne(int $productId): void
    {
        $this->message = '';
        $this->cart()->removeOne(auth()->user(), $productId);
        $this->dispatch('shop-cart-changed');
    }

    private function openSheet(Product $dish): void
    {
        $this->reset('variantId', 'single', 'multi', 'sheetError');
        $this->customizing = $dish->id;
        // Live walk-through 2026-11-03: a checkbox bound to a missing key is a true/false switch in Livewire 3, not a
        // list, so every "pick several" group starts as an empty list (otherwise extras are lost or sent as id 1).
        foreach ($this->groupsFor($dish) as $group) {
            if ($group->max_select !== 1) {
                $this->multi[$group->id] = [];
            }
        }
        if ($dish->hasChoices()) {
            $first = $dish->activeVariants->first();
            $this->variantId = $first?->id;
        }
    }

    /** A new pick clears the last "Pick 1" / "up to 2" message. */
    public function updated(string $property): void
    {
        if ($property === 'variantId' || str_starts_with($property, 'single') || str_starts_with($property, 'multi')) {
            $this->sheetError = '';
        }
    }

    public function closeSheet(): void
    {
        $this->reset('customizing', 'variantId', 'single', 'multi', 'sheetError');
    }

    /** "Add item" in the sheet. */
    public function addCustomized(): void
    {
        $dish = $this->customizing ? $this->dish($this->customizing) : null;
        if (! $dish) {
            $this->closeSheet();
            $this->message = SiteText::get('food.cart.dish_gone');

            return;
        }
        $this->sheetError = '';
        foreach ($this->groupsFor($dish) as $group) {
            if ($group->problemWith($this->chosenIds()) !== null) {
                $this->sheetError = SiteText::get('food.cart.group_rule', ['group' => $group->name, 'rule' => FoodCart::ruleText($group)]);

                return;
            }
        }
        if ($this->tryAdd(['product' => $dish->id, 'variant' => $dish->hasChoices() ? $this->variantId : null, 'addOns' => $this->chosenIds()])) {
            $this->closeSheet();
        }
    }

    /** "Start a new cart": drop the other restaurant's dishes, then add. */
    public function replaceCart(): void
    {
        $pending = $this->conflict['pending'] ?? null;
        $this->conflict = null;
        if ($pending) {
            $this->tryAdd($pending, replace: true);
            $this->closeSheet();
        }
    }

    public function keepCart(): void
    {
        $this->conflict = null;
    }

    /** @param array{product:int, variant:?int, addOns:int[]} $pending */
    private function tryAdd(array $pending, bool $replace = false): bool
    {
        $dish = $this->dish($pending['product']);
        $variant = $pending['variant'] ? $dish?->activeVariants()->find($pending['variant']) : null;
        try {
            if (! $dish) {
                throw new \RuntimeException(SiteText::get('food.cart.dish_gone'));
            }
            $this->cart()->add(auth()->user(), $dish, $variant, $pending['addOns'], $replace);
        } catch (FoodCartConflict $e) {
            $this->conflict = ['inCart' => $e->inCart, 'pending' => $pending];

            return false;
        } catch (\RuntimeException $e) {
            if ($this->customizing) {
                $this->sheetError = $e->getMessage();
            } else {
                $this->message = $e->getMessage();
            }

            return false;
        }
        $this->dispatch('shop-cart-changed');

        return true;
    }

    /** @return int[] */
    private function chosenIds(): array
    {
        $ids = array_values(array_filter(array_map('intval', $this->single)));
        foreach ($this->multi as $groupIds) {
            foreach ((array) $groupIds as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function groupsFor(Product $dish)
    {
        return $dish->addOnGroups()->where('add_on_groups.is_active', true)
            ->with(['addOns' => fn ($q) => $q->where('is_active', true)->orderBy('price')->orderBy('name')])->get()
            ->filter(fn (AddOnGroup $g) => $g->addOns->isNotEmpty() || $g->isRequired());
    }

    public function render()
    {
        $symbol = Setting::get('locale.currency_symbol', '₹');
        $term = trim($this->search);
        $dishes = Product::where('store_id', $this->store->id)->where('is_active', true)->where('is_approved', true)
            ->where(fn ($q) => $q->whereNull('out_of_stock_until')->orWhere('out_of_stock_until', '<=', now()))
            ->when($this->vegOnly, fn ($q) => $q->where('food_type', 'veg'))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('description', 'like', "%{$term}%")))
            ->with(['category:id,name,position', 'activeVariants'])
            ->withCount(['addOnGroups as groups_count' => fn ($q) => $q->where('add_on_groups.is_active', true)])
            ->orderBy('name')->get();

        $sections = $dishes->groupBy(fn ($d) => $d->category?->name ?? '')
            ->sortBy(fn ($items, $name) => [$name === '' ? 1 : 0, $items->first()->category?->position ?? 0, $name]);

        $rating = Review::whereHas('marketplaceOrder', fn ($q) => $q->where('store_id', $this->store->id))->selectRaw('avg(rating) as avg, count(*) as n')->first();
        $this->store->loadMissing(['hours', 'franchise']);

        $sheetDish = $this->customizing ? $dishes->firstWhere('id', $this->customizing) ?? $this->dish($this->customizing) : null;
        $sheetGroups = $sheetDish ? $this->groupsFor($sheetDish) : collect();
        $sheetTotal = null;
        if ($sheetDish) {
            $variant = $sheetDish->hasChoices() ? $sheetDish->activeVariants->firstWhere('id', $this->variantId) : null;
            $variant?->setRelation('product', $sheetDish);
            $base = $variant ? $variant->effectivePrice() : $sheetDish->effective_price;
            $addOns = $sheetGroups->flatMap->addOns->whereIn('id', $this->chosenIds())->sum('price');
            $sheetTotal = (float) $base + (float) $addOns;
        }

        $packaging = app(FoodCheckout::class)->packagingCharge($this->store);

        return view('livewire.customer.food.restaurant-show', [
            'card' => Home::card($this->store, $this->foodCity->slug, $rating),
            'sections' => $sections,
            'qty' => $this->cart()->quantities(auth()->user()),
            'sheetDish' => $sheetDish,
            'sheetGroups' => $sheetGroups,
            'sheetTotal' => $sheetTotal,
            'packaging' => $packaging > 0 ? SiteText::get('food.packaging_note', ['amount' => $symbol.rtrim(rtrim(number_format($packaging, 2), '0'), '.')]) : null,
            'city' => $this->foodCity,
            'currencySymbol' => $symbol,
        ])->layout('components.layouts.customer', [
            'title' => SiteText::get('food.meta.restaurant_title', ['restaurant' => $this->store->name, 'city' => $this->foodCity->name]),
            'metaDescription' => \Illuminate\Support\Str::limit($this->store->description ?: SiteText::get('food.meta.restaurant_description', ['restaurant' => $this->store->name, 'city' => $this->foodCity->name, 'brand' => Setting::get('branding.platform_name', '1CallFix')]), 155),
            'ogImage' => $this->store->logo_url,
            'indexable' => $term === '' && ! $this->vegOnly && app(\App\Services\Seo\CityPages::class)->restaurantIndexable($this->store),
            'schema' => [[
                '@context' => 'https://schema.org', '@type' => 'Restaurant', 'name' => $this->store->name,
                'servesCuisine' => $this->store->cuisineList(), 'address' => (string) $this->store->address_line,
                'image' => $this->store->logo_url,
            ]],
        ]);
    }
}
