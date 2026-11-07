<?php

namespace App\Actions;

use App\Exceptions\ModuleNotActiveException;
use App\Models\CartItem;
use App\Models\MarketplaceOrder;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Store;
use App\Notifications\MarketplaceOrderStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\CartService;
use App\Services\ModuleActivationService;
use App\Services\OrderCodeService;
use App\Services\WalletService;
use App\Support\FoodOrderClock;
use Illuminate\Support\Facades\DB;

/**
 * Phase 24 (Marketplace Foundation) -- checkout. Same module-activation
 * gate + DB-transaction/wallet-payment shape as every prior vertical, keyed
 * off the STORE's own `module` (not a fixed constant -- this is the one
 * real place a Marketplace order genuinely differs from its predecessors,
 * since it serves four verticals).
 *
 * The real concurrency-safety step: every distinct `Product`/`ProductVariant`
 * row referenced by the cart is `lockForUpdate()`'d, in a stable
 * id-ascending order (avoids a lock-ordering deadlock between two
 * concurrent checkouts touching overlapping products), stock verified
 * sufficient, then decremented -- all inside the same transaction as the
 * order row itself. Unlike Property Rental's date-availability design,
 * no UNIQUE-constraint backstop is needed here: the row already exists at
 * checkout time (created at catalog-management time), so `lockForUpdate()`
 * on the EXISTING row is itself the complete guarantee -- see
 * PHASE_24_MARKETPLACE_FOUNDATION_ARCHITECTURE.md §5.
 */
class CreateMarketplaceOrderAction
{
    public function __construct(
        private ModuleActivationService $moduleActivation,
        private CartService $cart,
        private OrderCodeService $orderCode,
        private \App\Services\Food\FoodCheckout $food,
    ) {
    }

    public function execute(array $data): MarketplaceOrder
    {
        $store = Store::findOrFail($data['store_id']);
        if (! $store->isSellable()) {
            throw new \RuntimeException('This store is not taking orders.');
        }
        if (! $store->is_open) {
            throw new \RuntimeException('This store is closed right now.');
        }
        // Food F2: a restaurant also needs its FSSAI number and must be inside its opening hours.
        $isFood = $store->isRestaurant();
        if ($isFood) {
            $this->food->assertRestaurantTakingOrders($store);
        }

        $scope = [
            'zone_id' => $store->zone_id, 'franchise_id' => $store->franchise_id,
            'city_id' => $store->franchise?->city_id, 'country_id' => $store->franchise?->country_id,
        ];
        if (! $this->moduleActivation->isActive($store->module, array_filter($scope))) {
            throw new ModuleNotActiveException($store->module);
        }

        $orderType = $data['order_type'] ?? 'delivery';
        if ($orderType === 'delivery' && empty($data['delivery_address_id'])) {
            throw new \RuntimeException('A delivery address is required for delivery orders.');
        }

        $customer = \App\Models\User::findOrFail($data['customer_id']);
        $address = null;

        // NO PAYMENT, NO BOOKING (owner 2026-11-07): the customer's unpaid online order ends first (its stock and coupon
        // go back), so ordering again after leaving the payment window never leaves two — and its coupon counts once.
        app(\App\Services\Payments\OrderAttempts::class)->replaceShopFor($customer->id);

        // E-commerce Phase 2: the address must be the customer's own, and a shop delivers only within its own city.
        if ($orderType === 'delivery') {
            $address = \App\Models\Address::where('user_id', $customer->id)->find($data['delivery_address_id']);
            if (! $address) {
                throw new \RuntimeException('Choose one of your saved addresses.');
            }
            if ($address->franchise_id !== null && (int) $address->franchise_id !== (int) $store->franchise_id) {
                throw new \RuntimeException('This shop does not deliver to that address. Choose another address or pick it up.');
            }
        }

        // Shop checkout polish 2026-11-02: "Send to someone else" (delivery only). Both fields or neither.
        $recipientName = $orderType === 'delivery' ? trim((string) ($data['recipient_name'] ?? '')) : '';
        $recipientPhone = $orderType === 'delivery' ? preg_replace('/[\s-]+/', '', (string) ($data['recipient_phone'] ?? '')) : '';
        if ($recipientName !== '' || $recipientPhone !== '') {
            if ($recipientName === '' || mb_strlen($recipientName) > 100) {
                throw new \RuntimeException('Enter the name of the person receiving it.');
            }
            if (! preg_match('/^\+?[0-9]{10,15}$/', $recipientPhone)) {
                throw new \RuntimeException('Enter a 10-digit mobile number for the person receiving it.');
            }
        }
        $lines = $this->cart->listForStore($customer, $store->id);

        if ($lines->isEmpty()) {
            throw new \RuntimeException('Your cart is empty for this store.');
        }

        // E-commerce Phase 2: the customer pays today's price. A line added before the seller changed the price (or
        // a discount ended) is re-priced here, never charged at the old snapshot.
        foreach ($lines as $line) {
            $current = $line->variant ? (float) $line->variant->effectivePrice() : (float) ($line->product?->effective_price ?? $line->unit_price_snapshot);
            if (round($current, 2) !== round((float) $line->unit_price_snapshot, 2)) {
                $line->forceFill(['unit_price_snapshot' => $current])->save();
            }
        }

        // Food F2: sold out today, and each dish's add-on choices against its group rules.
        if ($isFood) {
            $this->food->assertLines($lines);
        }

        $paymentMethod = $data['payment_method'] ?? 'online';

        // THUMB RULE — ONLINE PAYMENT ONLY FOR ALL BENEFITS (owner 2026-11-02, "Meesho way"): cash is always
        // allowed, but a cash order pays the full price of every item: no product discount ever reaches cash.
        // Online and wallet pay the offer price. The cart keeps the offer price; cash is priced here, per line.
        if ($paymentMethod === 'cash') {
            foreach ($lines as $line) {
                $line->unit_price_snapshot = $line->cashUnitPrice();
            }
        }

        $subtotal = $lines->sum(fn (CartItem $l) => ((float) $l->unit_price_snapshot + $this->cart->addOnsTotalFor($l)) * $l->quantity);

        if ($subtotal < (float) $store->minimum_order_amount) {
            throw new \RuntimeException("This store requires a minimum order of {$store->minimum_order_amount}.");
        }

        // THUMB RULE (owner 2026-10-28): free delivery above the store's threshold is a benefit, so a cash order
        // always pays the store's delivery fee. Online and wallet orders get the free delivery.
        $deliveryFee = 0.0;
        $distanceKm = null;
        if ($orderType === 'delivery') {
            // Food F2: the distance fee and delivery radius from Food Settings (unset = the restaurant's flat fee).
            [$fullFee, $distanceKm] = $isFood ? $this->food->deliveryFee($store, $address) : [(float) $store->delivery_fee_flat, null];
            $freeAbove = $store->free_delivery_above_amount;
            $freeDelivery = $paymentMethod !== 'cash' && $freeAbove !== null && $subtotal >= (float) $freeAbove;
            $deliveryFee = $freeDelivery ? 0.0 : $fullFee;
        }
        $packagingCharge = $isFood ? $this->food->packagingCharge($store) : 0.0;

        // E-commerce Phase 0: the cart price already has the product's discount % taken off
        // (Product::effective_price). Record how much that discount was, because a discount is a benefit and
        // decides whether cash is allowed (thumb rule below). The subtotal is not reduced again.
        $discountAmount = $paymentMethod === 'cash' ? 0.0 : round($lines->sum(fn (CartItem $l) => $this->lineDiscount($l)), 2);

        // Store-level tax rate only -- products carry their own `tax_percent`
        // column (real 6amMart evidence) but it is deliberately NOT combined
        // into this calculation without a real evidenced rule for how the
        // two rates interact; that's a deferred refinement, not an omission.
        if (! in_array($paymentMethod, ['online', 'wallet', 'cash'], true)) {
            throw new \RuntimeException("Payment method '{$paymentMethod}' is not available.");
        }

        // Belt and braces: a cash order can never carry a discount (priced at full price above).
        if ($paymentMethod === 'cash' && $discountAmount > 0) {
            throw new \RuntimeException('Discounted prices are for online payment only.');
        }

        // Coupons (engine connected for 'commerce' 2026-10-29): validated here, re-validated and reserved under the
        // coupon lock inside the order transaction. A coupon is a benefit, so the engine refuses cash itself.
        $couponCode = trim((string) ($data['coupon_code'] ?? ''));
        $couponCtx = null;
        $couponDiscount = 0.0;
        if ($couponCode !== '') {
            if ($paymentMethod === 'cash') {
                throw new \RuntimeException(\App\Exceptions\CouponException::ONLINE_ONLY_MESSAGE);
            }
            $couponCtx = $this->couponContext($store, $customer, $lines, $paymentMethod, $couponCode);
            $pre = app(\App\Services\Coupons\CouponService::class)->validate($couponCtx);
            if (! $pre->eligible) {
                throw new \RuntimeException($pre->message ?: \App\Services\Coupons\PromotionResult::GENERIC_MESSAGE);
            }
            $couponDiscount = round(min($pre->discountTotal, $subtotal), 2);
        }

        // Store-level GST on the value after the coupon (the coupon reduces the price of the supply).
        // Food F2: restaurant food is taxed under section 9(5): the platform charges the Food Settings rate on food +
        // packaging and pays it itself; the restaurant's own tax rate is not used. Unset rate = no GST.
        $taxByPlatform = false;
        if ($isFood) {
            $gst = $this->food->gstPercent($store);
            $taxByPlatform = $gst !== null;
            $taxAmount = $gst === null ? 0.0 : round(($subtotal - $couponDiscount + $packagingCharge) * $gst / 100, 2);
        } else {
            $taxAmount = round(($subtotal - $couponDiscount) * (float) $store->tax_percent / 100, 2);
        }
        $totalAmount = round($subtotal - $couponDiscount + $packagingCharge + $deliveryFee + $taxAmount, 2);

        $order = DB::transaction(function () use ($lines, $store, $customer, $orderType, $data, $subtotal, $discountAmount, $deliveryFee, $taxAmount, $totalAmount, $paymentMethod, $couponCtx, $couponCode, $couponDiscount, $recipientName, $recipientPhone, $isFood, $packagingCharge, $distanceKm, $taxByPlatform) {
            $this->lockAndDecrementStock($lines, $isFood);

            $order = MarketplaceOrder::create([
                'code' => $this->orderCode->generateForMarketplaceOrder($store->franchise),
                'franchise_id' => $store->franchise_id,
                'zone_id' => $store->zone_id,
                'customer_id' => $customer->id,
                'store_id' => $store->id,
                'module' => $store->module,
                'order_type' => $orderType,
                'delivery_address_id' => $data['delivery_address_id'] ?? null,
                'recipient_name' => $recipientName !== '' ? $recipientName : null,
                'recipient_phone' => $recipientPhone !== '' ? $recipientPhone : null,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'packaging_charge' => $packagingCharge,
                'distance_km' => $distanceKm,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'tax_by_platform' => $taxByPlatform,
                'total_amount' => $totalAmount,
                'status' => 'pending',
                'payment_status' => 'pending',
                'payment_method' => $paymentMethod,
                'special_instructions' => $data['special_instructions'] ?? null,
                'coupon_code' => $couponCtx ? strtoupper($couponCode) : null,
                'coupon_discount_amount' => $couponDiscount,
            ]);

            if ($couponCtx) {
                [$result, $usage] = app(\App\Services\Coupons\CouponService::class)->reserve($couponCtx);
                if (round(min($result->discountTotal, $subtotal), 2) !== $couponDiscount) {
                    throw new \RuntimeException('This coupon changed while you were checking out. Please try again.');
                }
                $usage->forceFill(['marketplace_order_id' => $order->id])->save();
            }

            foreach ($lines as $line) {
                $addOnsTotal = $this->cart->addOnsTotalFor($line);
                $order->items()->create([
                    'product_id' => $line->product_id,
                    'product_variant_id' => $line->product_variant_id,
                    'product_name_snapshot' => $line->product->name,
                    'variant_name_snapshot' => $line->variant?->name,
                    'add_ons_snapshot' => $this->addOnsSnapshot($line),
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price_snapshot,
                    'add_ons_total' => $addOnsTotal,
                    'line_total' => round(((float) $line->unit_price_snapshot + $addOnsTotal) * $line->quantity, 2),
                ]);
            }

            // An online order keeps the cart until its payment arrives (MarketplacePaymentService::markPaid() empties it):
            // leaving the payment window, the customer still has the cart and can order again, e.g. with cash.
            if ($paymentMethod !== 'online') {
                $this->cart->clearForStore($customer, $store->id);
            }

            if ($paymentMethod === 'wallet') {
                $this->payWithWallet($order);
            }

            // Food F2: the restaurant's accept clock starts now for cash and wallet; for online it starts when the
            // payment lands (MarketplacePaymentService::markPaid()).
            if ($isFood && ! $order->isAwaitingOnlinePayment()) {
                FoodOrderClock::startAcceptClock($order);
            }

            return $order;
        });

        // An online order is confirmed to the customer when the payment lands (MarketplacePaymentService::markPaid()).
        if ($order->customer && ! $order->isAwaitingOnlinePayment()) {
            $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);
            $order->customer->notify(new MarketplaceOrderStatusNotification('created', $order, $channels));
        }
        if (! $order->isAwaitingOnlinePayment()) {
            MarketplaceOrderStatusNotification::toSeller($order);
        }

        return $order;
    }

    /**
     * Locks every distinct Product/ProductVariant row the cart references,
     * id-ascending, verifies sufficient stock, then decrements. A variant's
     * own stock is authoritative when present; a plain product's own
     * `stock` column is authoritative when it has no variant selected.
     */
    private function lockAndDecrementStock(\Illuminate\Support\Collection $lines, bool $isFood = false): void
    {
        // Food F2: dishes are cooked to order, never counted (Seller\Products::RESTAURANT_STOCK). Availability is the
        // dish's on/off switch and "Sold out today" (FoodCheckout::assertLines()).
        if ($isFood) {
            foreach ($lines as $line) {
                $product = $line->product;
                $variantGone = $line->product_variant_id && (! $line->variant || ! $line->variant->is_active);
                if (! $product || ! $product->is_active || ! $product->is_approved || $variantGone) {
                    throw new \RuntimeException("\"{$line->product?->name}\" is no longer available.");
                }
            }

            return;
        }

        $productIds = $lines->whereNull('product_variant_id')->pluck('product_id')->unique()->sort()->values();
        $variantIds = $lines->whereNotNull('product_variant_id')->pluck('product_variant_id')->unique()->sort()->values();

        $products = $productIds->isEmpty() ? collect() : Product::whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');
        $variants = $variantIds->isEmpty() ? collect() : ProductVariant::whereIn('id', $variantIds)->lockForUpdate()->get()->keyBy('id');

        foreach ($lines as $line) {
            if ($line->product_variant_id) {
                $variant = $variants[$line->product_variant_id] ?? null;
                // Availability re-check: a product/variant browsed (or added
                // to cart) while active/approved can be deactivated by its
                // store/admin before checkout actually runs. The variant
                // itself is checked against its own LOCKED row; the parent
                // product's active/approved flags are checked against the
                // cart line's own eager-loaded copy (a rare-to-change flag,
                // unlike stock, so this doesn't need the same hard lock).
                // Same "browse-time filter is not the safety check"
                // discipline PropertyAvailabilityService's own docblock states.
                if (! $variant || ! $variant->is_active || ! $line->product->is_active || ! $line->product->is_approved) {
                    throw new \RuntimeException("\"{$line->product->name} ({$variant?->name})\" is no longer available.");
                }
                if ($variant->stock < $line->quantity) {
                    throw new \RuntimeException("Insufficient stock for \"{$line->product->name} ({$variant->name})\".");
                }
                $variant->decrement('stock', $line->quantity);
            } else {
                $product = $products[$line->product_id] ?? null;
                if (! $product || ! $product->is_active || ! $product->is_approved) {
                    throw new \RuntimeException("\"{$line->product->name}\" is no longer available.");
                }
                if ($product->stock < $line->quantity) {
                    throw new \RuntimeException("Insufficient stock for \"{$line->product->name}\".");
                }
                $product->decrement('stock', $line->quantity);
            }
        }
    }

    /**
     * The discount inside a cart line's price. Product::effective_price takes discount_percent off the product
     * price, and off a variant's own price too (product choices 2026-11-02, ProductVariant::effectivePrice()).
     * Worked back from the cart's fixed price, so a later product price change does not invent a discount.
     */
    /** The coupon engine's view of this shop order: one line per bag line (no service targets ever match). */
    public function couponContext(Store $store, \App\Models\User $customer, $lines, string $paymentMethod, string $code): \App\Services\Coupons\PromotionContext
    {
        $built = [];
        foreach ($lines->values() as $i => $line) {
            $built[] = [
                'line_ref' => 'm'.$i,
                'product_id' => $line->product_id,
                'marketplace_category_id' => $line->product?->marketplace_category_id,
                'line_total' => round(((float) $line->unit_price_snapshot + $this->cart->addOnsTotalFor($line)) * $line->quantity, 2),
                'flash_applied' => false,
                'entitlement_covered' => false,
            ];
        }

        return new \App\Services\Coupons\PromotionContext(
            module: $store->module,
            franchiseId: $store->franchise_id,
            cityId: $store->franchise?->city_id,
            zoneId: $store->zone_id,
            customer: $customer,
            paymentMethod: $paymentMethod,
            lines: $built,
            code: $code,
            countryId: $store->franchise?->country_id,
        );
    }

    private function lineDiscount(CartItem $line): float
    {
        $percent = min(max((float) $line->product->discount_percent, 0), 100);
        if ($percent <= 0) {
            return 0.0;
        }

        $paid = (float) $line->unit_price_snapshot * $line->quantity;
        $base = $line->variant ? $line->variant->listPrice() : (float) $line->product->price;
        $listPrice = $percent >= 100 ? $base * $line->quantity : $paid / (1 - $percent / 100);

        return max(round($listPrice - $paid, 2), 0.0);
    }

    private function addOnsSnapshot(CartItem $line): array
    {
        if (empty($line->add_on_ids)) {
            return [];
        }

        return \App\Models\AddOn::whereIn('id', $line->add_on_ids)->get(['id', 'name', 'price'])
            ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'price' => (float) $a->price])
            ->values()->all();
    }

    private function payWithWallet(MarketplaceOrder $order): void
    {
        $scope = array_filter(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);

        if (Setting::get('payment.wallet_enabled', null, $scope) !== '1') {
            throw new \RuntimeException('Wallet payments are not enabled.');
        }

        app(WalletService::class)->debit(
            $order->customer,
            (float) $order->total_amount,
            reason: "Payment for order {$order->code}",
            ref: "marketplace_order:{$order->id}:wallet-payment"
        );

        Payment::create([
            'marketplace_order_id' => $order->id,
            'purpose' => 'marketplace_order',
            'amount' => $order->total_amount,
            'gateway' => 'wallet',
            'status' => 'captured',
            'captured_at' => now(),
        ]);

        $order->payment_status = 'paid';
        $order->save();
    }
}
