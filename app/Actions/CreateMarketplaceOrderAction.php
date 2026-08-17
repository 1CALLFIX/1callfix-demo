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
    ) {
    }

    public function execute(array $data): MarketplaceOrder
    {
        $store = Store::findOrFail($data['store_id']);

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
        $lines = $this->cart->listForStore($customer, $store->id);

        if ($lines->isEmpty()) {
            throw new \RuntimeException('Your cart is empty for this store.');
        }

        $subtotal = $lines->sum(fn (CartItem $l) => ((float) $l->unit_price_snapshot + $this->cart->addOnsTotalFor($l)) * $l->quantity);

        if ($subtotal < (float) $store->minimum_order_amount) {
            throw new \RuntimeException("This store requires a minimum order of {$store->minimum_order_amount}.");
        }

        $deliveryFee = 0.0;
        if ($orderType === 'delivery') {
            $freeAbove = $store->free_delivery_above_amount;
            $deliveryFee = ($freeAbove !== null && $subtotal >= (float) $freeAbove) ? 0.0 : (float) $store->delivery_fee_flat;
        }

        // Store-level tax rate only -- products carry their own `tax_percent`
        // column (real 6amMart evidence) but it is deliberately NOT combined
        // into this calculation without a real evidenced rule for how the
        // two rates interact; that's a deferred refinement, not an omission.
        $taxAmount = round($subtotal * (float) $store->tax_percent / 100, 2);
        $totalAmount = round($subtotal + $deliveryFee + $taxAmount, 2);
        $paymentMethod = $data['payment_method'] ?? 'online';

        $order = DB::transaction(function () use ($lines, $store, $customer, $orderType, $data, $subtotal, $deliveryFee, $taxAmount, $totalAmount, $paymentMethod) {
            $this->lockAndDecrementStock($lines);

            $order = MarketplaceOrder::create([
                'code' => $this->orderCode->generateForMarketplaceOrder($store->franchise),
                'franchise_id' => $store->franchise_id,
                'zone_id' => $store->zone_id,
                'customer_id' => $customer->id,
                'store_id' => $store->id,
                'module' => $store->module,
                'order_type' => $orderType,
                'delivery_address_id' => $data['delivery_address_id'] ?? null,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'status' => 'pending',
                'payment_method' => $paymentMethod,
                'special_instructions' => $data['special_instructions'] ?? null,
            ]);

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

            $this->cart->clearForStore($customer, $store->id);

            if ($paymentMethod === 'wallet') {
                $this->payWithWallet($order);
            }

            return $order;
        });

        if ($order->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);
            $order->customer->notify(new MarketplaceOrderStatusNotification('created', $order, $channels));
        }

        return $order;
    }

    /**
     * Locks every distinct Product/ProductVariant row the cart references,
     * id-ascending, verifies sufficient stock, then decrements. A variant's
     * own stock is authoritative when present; a plain product's own
     * `stock` column is authoritative when it has no variant selected.
     */
    private function lockAndDecrementStock(\Illuminate\Support\Collection $lines): void
    {
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

        if (Setting::get('payment.wallet_enabled', '1', $scope) !== '1') {
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
