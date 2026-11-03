<?php

namespace App\Livewire\Seller;

use App\Livewire\Seller\Concerns\InteractsWithSeller;
use App\Models\MarketplaceOrder;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Services\ActivityLogger;
use App\Support\FoodSettings;
use Livewire\Component;

/**
 * Seller home: where the shop stands (approval, documents, Commerce switched on here), the open/closed switch, and
 * today's numbers. Opening needs an approved, active shop; customers can only buy when Commerce is on for its area.
 */
class Dashboard extends Component
{
    use InteractsWithSeller;

    public string $notice = '';

    public function toggleOpen(): void
    {
        $store = $this->shop();
        if (! $store->isSellable()) {
            $this->notice = 'Your shop can open once it is approved.';

            return;
        }
        if (! $store->is_open && $store->missingFssai()) {
            $this->notice = 'Add your FSSAI licence number on the Restaurant page before you open.';

            return;
        }
        $store->update(['is_open' => ! $store->is_open]);
        $this->notice = $store->is_open ? 'Your shop is open for orders.' : 'Your shop is closed. Customers cannot order until you open it.';
    }

    /** Food F1: "Busy" adds minutes to the cooking time customers see, for at most Food Settings' hours. */
    public function setBusy(int $minutes): void
    {
        $store = $this->shop();
        abort_unless($store->isRestaurant(), 404);
        if (! in_array($minutes, $this->busyChoices($store), true)) {
            $this->notice = 'Choose one of the listed times.';

            return;
        }
        $hours = (int) FoodSettings::get('food.busy_max_hours', $store->settingsScope());
        $store->update(['busy_extra_minutes' => $minutes, 'busy_until' => now()->addHours($hours)]);
        ActivityLogger::logModel(auth()->user(), $store, 'Restaurant set busy', ['extra_minutes' => $minutes, 'hours' => $hours]);
        $this->notice = "Busy: customers see {$minutes} more minutes. This switches off by itself after {$hours} h.";
    }

    public function clearBusy(): void
    {
        $store = $this->shop();
        $store->update(['busy_extra_minutes' => null, 'busy_until' => null]);
        $this->notice = 'Back to your normal cooking time.';
    }

    /** 10, 20, … up to Food Settings' "most extra time". Empty = no busy button. */
    private function busyChoices(Store $store): array
    {
        $max = (int) FoodSettings::get('food.busy_max_extra_minutes', $store->settingsScope());

        return $max >= 10 ? range(10, $max - ($max % 10), 10) : [];
    }

    public function render()
    {
        $store = $this->shop()->load(['franchise', 'hours']);
        $seller = $this->seller();
        $today = MarketplaceOrder::where('store_id', $store->id)->whereDate('created_at', today());

        return view('livewire.seller.dashboard', [
            'store' => $store,
            'kycStatus' => $seller->kyc_status,
            'moduleLive' => $this->shopModuleLive($store),
            'ordersToday' => (clone $today)->count(),
            'salesToday' => (float) (clone $today)->where('status', 'completed')->sum('subtotal'),
            'activeOrders' => MarketplaceOrder::where('store_id', $store->id)->whereIn('status', ['pending', 'accepted', 'preparing', 'ready'])->count(),
            'productCount' => Product::where('store_id', $store->id)->count(),
            'awaitingApproval' => Product::where('store_id', $store->id)->where('is_approved', false)->count(),
            'lowStock' => Product::where('store_id', $store->id)->where('is_active', true)->where('stock', '<=', 3)->orderBy('stock')->limit(5)->get(['id', 'name', 'stock']),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
            'busyChoices' => $store->isRestaurant() ? $this->busyChoices($store) : [],
        ])->layout('components.layouts.seller', ['title' => 'Home']);
    }
}
