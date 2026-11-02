<?php

namespace App\Livewire\Customer\Shop;

use App\Actions\CreateMarketplaceOrderAction;
use App\Models\Address;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Zone;
use App\Services\Customer\CustomerLocationContext;
use App\Support\SiteText;
use App\Services\CartService;
use App\Services\Marketplace\MarketplacePaymentService;
use App\Services\ModuleActivationService;
use App\Services\WalletService;
use Livewire\Component;

/**
 * E-commerce Phase 2 — /shop/checkout/{store}: one shop's order. Delivery (a saved address in the shop's zone
 * cover) or pickup at the shop; pay online (Razorpay), from the wallet, or cash. Thumb rules shown as they apply:
 * a discounted item makes the order online-only, and free delivery above the shop's threshold is for online and
 * wallet payments only (cash always pays the delivery fee). The money is decided by CreateMarketplaceOrderAction;
 * this page only previews it with the same rules.
 */
class Checkout extends Component
{
    public Store $store;

    public string $orderType = 'delivery';

    public ?int $addressId = null;

    public string $paymentMethod = 'online';

    public string $note = '';

    public string $error = '';

    /** Coupon (online / wallet only — a coupon is a benefit). */
    public string $couponInput = '';

    public string $appliedCoupon = '';

    public float $couponDiscount = 0.0;

    public string $couponMessage = '';

    /** Shop checkout polish 2026-11-02: "Send to someone else" (delivery only). */
    public bool $sendToOther = false;

    public string $recipientName = '';

    public string $recipientPhone = '';

    /** Add an address without leaving checkout (same rules as Account -> Saved addresses). */
    public bool $addingAddress = false;

    public array $newAddress = ['label' => '', 'address_line' => '', 'landmark' => '', 'pincode' => ''];

    public ?float $locatedLat = null;

    public ?float $locatedLng = null;

    public ?int $locatedZoneId = null;

    public string $locatedZoneName = '';

    public string $addressError = '';

    public function applyCoupon(CreateMarketplaceOrderAction $create): void
    {
        $this->couponMessage = '';
        $code = strtoupper(trim($this->couponInput));
        if ($code === '') {
            return;
        }
        if ($this->paymentMethod === 'cash') {
            $this->couponMessage = \App\Exceptions\CouponException::ONLINE_ONLY_MESSAGE;

            return;
        }
        $ctx = $create->couponContext($this->store, auth()->user(), $this->lines(), $this->paymentMethod, $code);
        $result = app(\App\Services\Coupons\CouponService::class)->validate($ctx);
        if (! $result->eligible) {
            $this->reset('appliedCoupon', 'couponDiscount');
            $this->couponMessage = $result->message ?: \App\Services\Coupons\PromotionResult::GENERIC_MESSAGE;

            return;
        }
        $this->appliedCoupon = $code;
        $this->couponDiscount = round($result->discountTotal, 2);
        $this->couponMessage = \App\Support\SiteText::get('shop_checkout.coupon_applied');
    }

    public function removeCoupon(): void
    {
        $this->reset('appliedCoupon', 'couponDiscount', 'couponInput', 'couponMessage');
    }

    /** Switching to cash drops the coupon (benefits are online-only). */
    public function updatedPaymentMethod(string $value): void
    {
        if ($value === 'cash' && $this->appliedCoupon !== '') {
            $this->removeCoupon();
            $this->couponMessage = \App\Support\SiteText::get('shop_checkout.coupon_removed_cash');
        }
    }

    public function mount(Store $store): void
    {
        abort_unless($store->isSellable(), 404);
        abort_unless(app(ModuleActivationService::class)->isActive($store->module, array_filter([
            'zone_id' => $store->zone_id, 'franchise_id' => $store->franchise_id,
            'city_id' => $store->franchise?->city_id, 'country_id' => $store->franchise?->country_id,
        ])), 404);
        $this->store = $store;
        if (app(CartService::class)->listForStore(auth()->user(), $store->id)->isEmpty()) {
            $this->redirectRoute('customer.cart', navigate: true);

            return;
        }
        // The customer's own saved addresses only (never the send-to addresses kept from parcel orders),
        // their default first, else the newest.
        $this->addressId = $this->ownAddresses()->value('id');
        if ($this->hasDiscount()) {
            $this->paymentMethod = 'online';
        }
    }

    private function ownAddresses()
    {
        return Address::where('user_id', auth()->id())->listed()->orderByDesc('is_default')->latest('id');
    }

    public function startAddAddress(): void
    {
        $this->reset('newAddress', 'locatedLat', 'locatedLng', 'locatedZoneId', 'locatedZoneName', 'addressError');
        $this->addingAddress = true;
    }

    public function cancelAddAddress(): void
    {
        $this->addingAddress = false;
        $this->resetErrorBag();
    }

    /** "Use my current location" (the shared cfWireLocateButton helper calls this, as on Saved addresses). */
    public function useCurrentLocationForNewAddress(float $lat, float $lng, CustomerLocationContext $location): void
    {
        $this->addressError = '';
        validator(['lat' => $lat, 'lng' => $lng], ['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']])->validate();
        $zone = $location->nearestCoveringZone($lat, $lng);
        if (! $zone) {
            $this->reset('locatedLat', 'locatedLng', 'locatedZoneId', 'locatedZoneName');
            $this->addressError = SiteText::get('shop_checkout.not_served');

            return;
        }
        $this->locatedLat = $lat;
        $this->locatedLng = $lng;
        $this->locatedZoneId = $zone->id;
        $this->locatedZoneName = $zone->name;
    }

    /**
     * Save the new address and select it. Zone: the pinned location's, else the customer's chosen area when it is
     * in the shop's city, else the shop's own (a shop delivers only in its own city; the order action checks again).
     */
    public function saveAddress(CustomerLocationContext $location): void
    {
        $this->addressError = '';
        $data = $this->validate([
            'newAddress.label' => ['required', 'string', 'max:40'],
            'newAddress.address_line' => ['required', 'string', 'min:5', 'max:255'],
            'newAddress.landmark' => ['nullable', 'string', 'max:120'],
            'newAddress.pincode' => ['nullable', 'string', 'max:12'],
        ], [], ['newAddress.label' => 'name', 'newAddress.address_line' => 'address'])['newAddress'];

        $zone = $this->locatedZoneId ? Zone::where('is_active', true)->find($this->locatedZoneId) : null;
        if (! $zone && ($picked = $location->zone()) && (int) $picked->franchise_id === (int) $this->store->franchise_id) {
            $zone = $picked;
        }
        $zone ??= $this->store->zone;

        $address = Address::create([
            'user_id' => auth()->id(),
            'franchise_id' => $zone?->franchise_id ?? $this->store->franchise_id,
            'zone_id' => $zone?->id,
            'label' => trim($data['label']),
            'lat' => $this->locatedLat ?? $zone?->center_lat ?? $this->store->lat ?? 0,
            'lng' => $this->locatedLng ?? $zone?->center_lng ?? $this->store->lng ?? 0,
            'address_line' => trim($data['address_line']),
            'landmark' => trim((string) ($data['landmark'] ?? '')) ?: null,
            'city' => $this->store->franchise?->city?->name,
            'pincode' => trim((string) ($data['pincode'] ?? '')) ?: null,
            'is_default' => ! $this->ownAddresses()->exists(),
        ]);
        $this->addressId = $address->id;
        $this->addingAddress = false;
    }

    private function lines()
    {
        return app(CartService::class)->listForStore(auth()->user(), $this->store->id);
    }

    private function hasDiscount(): bool
    {
        return $this->lines()->contains(fn ($l) => $l->isDiscounted());
    }

    public function placeOrder(CreateMarketplaceOrderAction $create, MarketplacePaymentService $payments): void
    {
        $this->error = '';
        $this->validate([
            'orderType' => ['required', 'in:delivery,pickup'],
            'paymentMethod' => ['required', 'in:online,wallet,cash'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        if ($this->orderType === 'delivery' && $this->sendToOther) {
            $this->recipientPhone = preg_replace('/[\s-]+/', '', $this->recipientPhone);
            $this->validate([
                'recipientName' => ['required', 'string', 'max:100'],
                'recipientPhone' => ['required', 'regex:/^\+?[0-9]{10,15}$/'],
            ], ['recipientPhone.regex' => 'Enter a 10-digit mobile number.'], ['recipientName' => 'name', 'recipientPhone' => 'mobile number']);
        }
        if ($this->orderType === 'delivery') {
            if (! $this->addressId || ! $this->ownAddresses()->whereKey($this->addressId)->exists()) {
                $this->error = \App\Support\SiteText::get('shop_checkout.choose_address');

                return;
            }
        }

        try {
            $order = $create->execute([
                'store_id' => $this->store->id,
                'customer_id' => auth()->id(),
                'order_type' => $this->orderType,
                'delivery_address_id' => $this->orderType === 'delivery' ? $this->addressId : null,
                'recipient_name' => $this->orderType === 'delivery' && $this->sendToOther ? trim($this->recipientName) : null,
                'recipient_phone' => $this->orderType === 'delivery' && $this->sendToOther ? $this->recipientPhone : null,
                'payment_method' => $this->paymentMethod,
                'special_instructions' => trim($this->note) ?: null,
                'coupon_code' => $this->appliedCoupon ?: null,
            ]);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->dispatch('shop-cart-changed');

        if ($order->isAwaitingOnlinePayment()) {
            try {
                $checkout = $payments->startOnlinePayment($order);
            } catch (\RuntimeException $e) {
                // The order stays unpaid (and expires on its own); the order page offers "Pay now" again.
                session()->flash('shop_error', $e->getMessage());
                $this->redirectRoute('customer.shop.orders.show', $order, navigate: true);

                return;
            }
            $this->dispatch('razorpay-open', order: $checkout, redirect: route('customer.shop.orders.show', $order))->self();

            return;
        }

        $this->redirectRoute('customer.shop.orders.show', $order, navigate: true);
    }

    public function render()
    {
        $lines = $this->lines();
        $subtotal = (float) $lines->sum(fn ($l) => $l->unitPrice() * $l->quantity);
        $freeAbove = $this->store->free_delivery_above_amount;
        $freeDeliveryEarned = $freeAbove !== null && $subtotal >= (float) $freeAbove;
        $deliveryFee = $this->orderType === 'pickup' ? 0.0
            : (($this->paymentMethod !== 'cash' && $freeDeliveryEarned) ? 0.0 : (float) $this->store->delivery_fee_flat);
        $coupon = min($this->couponDiscount, $subtotal);
        $tax = round(($subtotal - $coupon) * (float) $this->store->tax_percent / 100, 2);
        $scope = array_filter(['zone_id' => $this->store->zone_id, 'franchise_id' => $this->store->franchise_id]);

        return view('livewire.customer.shop.checkout', [
            'lines' => $lines,
            'addresses' => $this->ownAddresses()->get(),
            'subtotal' => $subtotal,
            'deliveryFee' => $deliveryFee,
            'tax' => $tax,
            'total' => round($subtotal - $coupon + $deliveryFee + $tax, 2),
            'coupon' => $coupon,
            'freeDeliveryEarned' => $freeDeliveryEarned,
            'cashBlocked' => $this->hasDiscount() || $this->appliedCoupon !== '',
            // Name the discounted items, so the customer sees why cash is off.
            'discountedNames' => $lines->filter(fn ($l) => $l->isDiscounted())->map(fn ($l) => $l->product->name)->unique()->values(),
            'walletEnabled' => Setting::get('payment.wallet_enabled', null, $scope) === '1',
            'walletBalance' => app(WalletService::class)->balance(auth()->user()),
            'onlineEnabled' => Setting::get('payment.online_enabled', '1', $scope) === '1',
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.customer', ['title' => 'Checkout — '.$this->store->name]);
    }
}
