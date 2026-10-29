<?php

namespace App\Livewire\Seller;

use App\Livewire\Seller\Concerns\InteractsWithSeller;
use App\Models\MarketplaceCategory;
use App\Models\Product;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Support\MarketplaceSettings;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Seller → Products: the seller's own catalogue — add, edit, stock, photos, on/off. Owner rules applied here:
 *  - Phase 1 sells no food, medicine or regulated items: a name or description containing a Shop Settings blocked
 *    word is refused;
 *  - a new product (and a changed name, description or photo) waits for admin approval while Shop Settings →
 *    "New seller products wait for admin approval" is on (default). Price and stock changes go live at once.
 * Tax and commission are not the seller's to set (tax_percent stays as the admin sets it).
 */
class Products extends Component
{
    use InteractsWithSeller;
    use WithFileUploads;
    use WithPagination;

    private const MAX_PHOTOS = 5;

    #[Url]
    public string $search = '';

    #[Url(as: 'new')]
    public bool $creating = false;

    public ?int $editingId = null;

    public string $name = '';
    public string $description = '';
    public ?int $categoryId = null;
    public string $price = '';
    public string $discountPercent = '';
    public string $stock = '0';
    public string $maxCartQuantity = '';
    public bool $isActive = true;

    /** Paths already saved on the product being edited. */
    public array $photos = [];

    /** New uploads, saved on submit. */
    public array $uploads = [];

    public string $flashMessage = '';
    public string $flashType = 'success';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function startCreate(): void
    {
        $this->resetForm();
        $this->creating = true;
    }

    public function edit(int $productId): void
    {
        $product = $this->ownProduct($productId);
        $this->resetForm();
        $this->editingId = $product->id;
        $this->name = $product->name;
        $this->description = (string) $product->description;
        $this->categoryId = $product->marketplace_category_id;
        $this->price = (string) $product->price;
        $this->discountPercent = $product->discount_percent !== null ? (string) (float) $product->discount_percent : '';
        $this->stock = (string) $product->stock;
        $this->maxCartQuantity = $product->maximum_cart_quantity !== null ? (string) $product->maximum_cart_quantity : '';
        $this->isActive = (bool) $product->is_active;
        $this->photos = $product->images ?? [];
        $this->creating = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function removePhoto(int $index): void
    {
        unset($this->photos[$index]);
        $this->photos = array_values($this->photos);
    }

    public function save(): void
    {
        $store = $this->shop();
        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:3000'],
            'categoryId' => ['nullable', 'integer'],
            'price' => ['required', 'numeric', 'min:1', 'max:10000000'],
            'discountPercent' => ['nullable', 'numeric', 'min:0', 'max:90'],
            'stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'maxCartQuantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'uploads' => ['array', 'max:'.self::MAX_PHOTOS],
            'uploads.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ], [], ['categoryId' => 'category', 'discountPercent' => 'discount', 'maxCartQuantity' => 'limit per order']);

        if (($word = MarketplaceSettings::blockedWordIn($this->name.' '.$this->description)) !== null) {
            $this->addError('name', "Products with “{$word}” cannot be sold on the shop.");

            return;
        }
        if ($this->categoryId !== null && ! MarketplaceCategory::whereKey($this->categoryId)->where('module', $store->module)->where('is_active', true)->exists()) {
            $this->addError('categoryId', 'Choose one of the listed categories.');

            return;
        }
        if (count($this->photos) + count($this->uploads) > self::MAX_PHOTOS) {
            $this->addError('uploads', 'Up to '.self::MAX_PHOTOS.' photos.');

            return;
        }

        $paths = $this->photos;
        foreach ($this->uploads as $file) {
            $paths[] = $file->store('products/'.$store->id, 'public');
        }

        $fields = [
            'name' => trim($this->name),
            'description' => trim($this->description) ?: null,
            'marketplace_category_id' => $this->categoryId,
            'price' => round((float) $this->price, 2),
            'discount_percent' => $this->discountPercent === '' ? null : round((float) $this->discountPercent, 2),
            'stock' => (int) $this->stock,
            'maximum_cart_quantity' => $this->maxCartQuantity === '' ? null : (int) $this->maxCartQuantity,
            'is_active' => $this->isActive,
            'images' => $paths ?: null,
        ];
        $needsApproval = MarketplaceSettings::on('marketplace.seller_products_need_approval', $this->shopScope($store));

        if ($this->editingId) {
            $product = $this->ownProduct($this->editingId);
            $contentChanged = $product->name !== $fields['name'] || (string) $product->description !== (string) $fields['description']
                || ($product->images ?? []) !== ($fields['images'] ?? []);
            if ($needsApproval && $contentChanged) {
                $fields['is_approved'] = false;
            }
            foreach (array_diff($product->images ?? [], $paths) as $removed) {
                if (! str_contains($removed, '://')) {
                    Storage::disk('public')->delete($removed);
                }
            }
            $product->update($fields);
            ActivityLogger::logModel(auth()->user(), $product, 'Seller updated product', ['fields' => array_keys($product->getChanges())]);
            $this->flash(($fields['is_approved'] ?? true) === false ? 'Saved. The changes show to customers once approved.' : 'Saved.', 'success');
        } else {
            $product = Product::create($fields + ['store_id' => $store->id, 'is_approved' => ! $needsApproval, 'tax_percent' => 0]);
            ActivityLogger::logModel(auth()->user(), $product, 'Seller added product');
            $this->flash($needsApproval ? 'Product added. It shows to customers once approved.' : 'Product added.', 'success');
        }

        $this->resetForm();
    }

    /** Quick stock change from the list. */
    public function setStock(int $productId, string $value): void
    {
        $product = $this->ownProduct($productId);
        if (! preg_match('/^\d{1,7}$/', trim($value))) {
            $this->flash('Stock must be a whole number.', 'error');

            return;
        }
        $product->update(['stock' => (int) trim($value)]);
        $this->flash("Stock for {$product->name} is now {$product->stock}.", 'success');
    }

    public function toggleActive(int $productId): void
    {
        $product = $this->ownProduct($productId);
        $product->update(['is_active' => ! $product->is_active]);
    }

    public function delete(int $productId): void
    {
        $product = $this->ownProduct($productId);
        $product->delete();
        ActivityLogger::logModel(auth()->user(), $product, 'Seller removed product');
        $this->flash("{$product->name} removed.", 'success');
    }

    private function ownProduct(int $productId): Product
    {
        $product = Product::where('store_id', $this->shop()->id)->find($productId);
        abort_unless($product, 404);

        return $product;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'description', 'categoryId', 'price', 'discountPercent', 'maxCartQuantity', 'photos', 'uploads']);
        $this->stock = '0';
        $this->isActive = true;
        $this->creating = false;
        $this->resetErrorBag();
    }

    private function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }

    public function render()
    {
        $store = $this->shop();

        return view('livewire.seller.products', [
            'products' => Product::where('store_id', $store->id)
                ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
                ->with('category:id,name')->orderBy('name')->paginate(25),
            'categories' => MarketplaceCategory::where('module', $store->module)->where('is_active', true)->orderBy('position')->orderBy('name')->get(['id', 'name', 'parent_id']),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
            'maxPhotos' => self::MAX_PHOTOS,
        ])->layout('components.layouts.seller', ['title' => 'Products']);
    }
}
