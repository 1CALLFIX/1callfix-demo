<?php

namespace App\Livewire\Customer\Cart;

use App\Livewire\Customer\Concerns\HasCouponEntry;
use App\Models\ServiceCartItem;
use App\Services\Customer\ServiceCartService;
use App\Services\TimezoneResolver;
use App\Support\BookingSchedule;
use Livewire\Component;

/**
 * The services cart. Lines the customer added from service pages, bucketed
 * into "visits" by subcategory (ServiceCartService::groupedForUser), each
 * with an editable preferred time and quantity. "Proceed to checkout" hands
 * the whole cart to the bundle checkout — nothing here creates a booking or
 * a price.
 *
 * Every mutation re-scopes the target row to the authenticated user
 * (itemForUser); a cart-item id from the page can never touch another
 * customer's cart.
 */
class Index extends Component
{
    use HasCouponEntry;

    /** item id => 'Y-m-d\TH:i' local string ('' = ASAP). Bound per row. */
    public array $schedules = [];

    /** item id => validation message for that row's time field. */
    public array $scheduleErrors = [];

    /** item id => quantity message (e.g. over the per-line maximum). */
    public array $qtyErrors = [];

    public function mount(ServiceCartService $cart): void
    {
        $tz = app(TimezoneResolver::class);
        foreach ($cart->itemsFor(auth()->user()) as $item) {
            // Stored UTC -> the customer's own wall clock for the
            // datetime-local field, the inverse of BookingSchedule::parse().
            $this->schedules[$item->id] = $tz->toLocalInput($item->scheduled_at) ?? '';
        }
    }

    public function updatedSchedules($value, $key): void
    {
        $item = $this->itemForUser((int) $key);
        if (! $item) {
            return;
        }

        if (($msg = BookingSchedule::validate($value)) !== null) {
            $this->scheduleErrors[$key] = $msg;

            return;
        }

        unset($this->scheduleErrors[$key]);
        app(ServiceCartService::class)->updateSchedule($item, BookingSchedule::parse($value));
    }

    public function changeQty(int $itemId, int $delta): void
    {
        $item = $this->itemForUser($itemId);
        if (! $item) {
            return;
        }

        unset($this->qtyErrors[$itemId]);

        try {
            app(ServiceCartService::class)->updateQuantity($item, $item->quantity + $delta);
        } catch (\RuntimeException $e) {
            // Over ServiceCartService::MAX_QUANTITY — say so, don't cap silently.
            $this->qtyErrors[$itemId] = $e->getMessage();

            return;
        }

        $this->dispatch('cart-updated');
    }

    public function removeItem(int $itemId): void
    {
        $item = $this->itemForUser($itemId);
        if (! $item) {
            return;
        }

        app(ServiceCartService::class)->remove($item);
        unset($this->schedules[$itemId], $this->scheduleErrors[$itemId]);
        $this->dispatch('cart-updated');
    }

    public function proceed()
    {
        if (app(ServiceCartService::class)->lineCount(auth()->user()) < 1) {
            return null;
        }

        if ($this->scheduleErrors !== []) {
            return null;
        }

        return $this->redirectRoute('customer.checkout', navigate: true);
    }

    public function render()
    {
        $cart = app(ServiceCartService::class);

        return view('livewire.customer.cart.index', [
            'groups' => $cart->groupedForUser(auth()->user()),
            'estimateTotal' => $cart->estimateTotal(auth()->user()),
            'cashNote' => $this->cashNote($cart),
            'coupon' => $this->couponView(),
            'currencySymbol' => \App\Models\Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.customer', ['title' => 'Your cart']);
    }

    /** The full-price wording when any line is on an online-only offer; null otherwise. */
    private function cashNote(ServiceCartService $cart): ?string
    {
        $presenter = app(\App\Services\Customer\CatalogPresenter::class);
        $cashTotal = 0.0;
        $anyOffer = false;

        foreach ($cart->itemsFor(auth()->user()) as $item) {
            $card = $presenter->card($item->service);
            $anyOffer = $anyOffer || $card['offer_requires_online'];
            $cashTotal += (float) $card['cash_price'] * $item->quantity;
        }

        return $anyOffer ? $presenter->cashNote($cashTotal) : null;
    }

    protected function couponSurface(): string
    {
        return 'cart';
    }

    protected function couponItems(): array
    {
        return app(ServiceCartService::class)->itemsFor(auth()->user())
            ->map(fn ($item) => ['service' => $item->service, 'quantity' => max(1, (int) $item->quantity)])->all();
    }

    /** The cart has no address yet: the browsing location the catalog prices are shown for, else the default address. */
    protected function couponLocation(): array
    {
        $location = app(\App\Services\Customer\CustomerLocationContext::class);
        $franchiseId = $location->franchiseId();
        $zoneId = $location->viewerScope()['zone_id'] ?? null;

        if (! $franchiseId || ! $zoneId) {
            $address = \App\Models\Address::where('user_id', auth()->id())->whereNotNull('zone_id')->orderByDesc('is_default')->latest()->first();

            return [$address?->franchise_id, $address?->zone_id];
        }

        return [$franchiseId, $zoneId];
    }

    /** Online, the only payment an offer exists on; checkout re-judges with the real method and address. */
    protected function couponPaymentMethod(): string
    {
        return 'online';
    }

    /** Hand the applied code on to checkout. */
    protected function couponApplied(): void
    {
        session(['coupon.cart_code' => trim($this->couponCode)]);
    }

    /** The row, only if it belongs to the current customer. */
    private function itemForUser(int $id): ?ServiceCartItem
    {
        return ServiceCartItem::where('user_id', auth()->id())->find($id);
    }
}
