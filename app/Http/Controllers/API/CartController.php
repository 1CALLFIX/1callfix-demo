<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CartService;
use Illuminate\Http\Request;

/**
 * Phase 24 (Marketplace Foundation) — authenticated cart API. Ownership is
 * checked the same IDOR-safe way every other customer-facing endpoint in
 * this app does: a cart_item id alone is never sufficient, `user_id` must
 * match the caller.
 */
class CartController extends Controller
{
    /** GET /api/cart?store_id= */
    public function index(Request $request, CartService $cart)
    {
        $validated = $request->validate(['store_id' => 'required|integer|exists:stores,id']);

        $lines = $cart->listForStore($request->user(), $validated['store_id']);

        return response()->json([
            'items' => $lines,
            'subtotal' => $cart->subtotalForStore($request->user(), $validated['store_id']),
        ]);
    }

    /** POST /api/cart */
    public function store(Request $request, CartService $cart)
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'quantity' => 'required|integer|min:1',
            'add_on_ids' => 'nullable|array',
            'add_on_ids.*' => 'integer|exists:add_ons,id',
        ]);

        $product = Product::findOrFail($validated['product_id']);
        $variant = isset($validated['product_variant_id']) ? ProductVariant::findOrFail($validated['product_variant_id']) : null;

        if ($variant && $variant->product_id !== $product->id) {
            return response()->json(['message' => 'This variant does not belong to the given product.'], 422);
        }

        $addOnIds = $validated['add_on_ids'] ?? [];
        if (! empty($addOnIds)) {
            $validCount = \App\Models\AddOn::whereIn('id', $addOnIds)->where('store_id', $product->store_id)->count();
            if ($validCount !== count($addOnIds)) {
                return response()->json(['message' => 'One or more add-ons do not belong to this product\'s store.'], 422);
            }
        }

        try {
            $item = $cart->add($request->user(), $product, $validated['quantity'], $variant, $addOnIds);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Added to cart.', 'item' => $item], 201);
    }

    /** PATCH /api/cart/{cartItemId} */
    public function update(Request $request, int $cartItemId, CartService $cart)
    {
        $item = CartItem::find($cartItemId);
        if (! $item || $item->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Cart item not found.'], 404);
        }

        $validated = $request->validate(['quantity' => 'required|integer|min:0']);

        $cart->updateQuantity($item, $validated['quantity']);

        return response()->json(['message' => 'Cart updated.']);
    }

    /** DELETE /api/cart/{cartItemId} */
    public function destroy(Request $request, int $cartItemId, CartService $cart)
    {
        $item = CartItem::find($cartItemId);
        if (! $item || $item->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Cart item not found.'], 404);
        }

        $cart->remove($item);

        return response()->json(['message' => 'Removed from cart.']);
    }
}
