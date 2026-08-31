<?php

namespace App\Livewire\Customer\Cart;

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
    /** item id => 'Y-m-d\TH:i' local string ('' = ASAP). Bound per row. */
    public array $schedules = [];

    /** item id => validation message for that row's time field. */
    public array $scheduleErrors = [];

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

        app(ServiceCartService::class)->updateQuantity($item, $item->quantity + $delta);
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
            'currencySymbol' => \App\Models\Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.customer', ['title' => 'Your cart']);
    }

    /** The row, only if it belongs to the current customer. */
    private function itemForUser(int $id): ?ServiceCartItem
    {
        return ServiceCartItem::where('user_id', auth()->id())->find($id);
    }
}
