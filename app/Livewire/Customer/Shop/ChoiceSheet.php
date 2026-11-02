<?php

namespace App\Livewire\Customer\Shop;

use App\Livewire\Customer\Shop\Concerns\PicksProductChoices;
use App\Models\Product;
use App\Models\Setting;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Choice pop-up on the shop cards (owner 2026-11-02, Swiggy / Zepto / Flipkart Minutes style). "Choose" on a card with
 * sizes / colours / portions opens this panel on the same page: pick, Add to Cart, carry on shopping. Same rules as the
 * product page (PicksProductChoices). Without JavaScript, or on a page without this panel, "Choose" opens the product
 * page as before.
 */
class ChoiceSheet extends Component
{
    use PicksProductChoices;

    public ?Product $product = null;

    public array $picked = [];

    public string $missingChoice = '';

    public string $cartMessage = '';

    #[On('open-choice-sheet')]
    public function open(int $productId): void
    {
        $this->reset('picked', 'missingChoice', 'cartMessage');
        $this->product = Product::with('store.franchise.city')->where('is_active', true)->where('is_approved', true)
            ->whereHas('store', fn ($q) => $q->publiclyVisible())->find($productId);
        if ($this->product && ! $this->product->hasChoices()) {
            $this->product = null; // a plain product is added with "Add" on the card itself
        }
        if ($this->product) {
            $this->pickSingleValues();
        }
    }

    public function close(): void
    {
        $this->reset('product', 'picked', 'missingChoice', 'cartMessage');
    }

    public function render()
    {
        $data = ['currencySymbol' => Setting::get('locale.currency_symbol', '₹'), 'choices' => [], 'selected' => null, 'choiceQty' => 0, 'lowStock' => [], 'productUrl' => null];
        if ($this->product) {
            $data = array_merge($data, $this->choiceViewData());
            $citySlug = $this->product->store?->franchise?->city?->slug;
            $data['productUrl'] = $citySlug ? route('customer.shop.product', [$citySlug, $this->product->store->slug, $this->product->slug]) : null;
        }

        return view('livewire.customer.shop.choice-sheet', $data);
    }
}
