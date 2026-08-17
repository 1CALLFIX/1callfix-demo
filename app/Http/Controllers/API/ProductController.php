<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AddOn;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\Request;

/** Phase 24 (Marketplace Foundation) — public browse API, same reasoning as StoreController. */
class ProductController extends Controller
{
    /** GET /api/stores/{storeId}/products?category_id=&search= */
    public function index(Request $request, int $storeId)
    {
        $store = Store::where('is_active', true)->find($storeId);
        if (! $store) {
            return response()->json(['message' => 'Store not found.'], 404);
        }

        $query = Product::where('store_id', $storeId)->where('is_active', true)->where('is_approved', true)
            ->with(['category', 'variants' => fn ($q) => $q->where('is_active', true)]);

        if ($request->filled('category_id')) {
            $query->where('marketplace_category_id', $request->integer('category_id'));
        }
        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->string('search').'%');
        }

        $products = $query->orderBy('id')->limit(100)->get();

        return response()->json(['products' => $products, 'add_ons' => AddOn::where('store_id', $storeId)->where('is_active', true)->get()]);
    }

    /** GET /api/products/{id} */
    public function show(int $productId)
    {
        $product = Product::where('is_active', true)->where('is_approved', true)
            ->with(['category', 'store', 'variants' => fn ($q) => $q->where('is_active', true)])
            ->find($productId);

        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        return response()->json(['product' => $product]);
    }
}
