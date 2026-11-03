<?php

namespace App\Livewire\Seller;

use App\Livewire\Seller\Concerns\InteractsWithSeller;
use App\Models\MarketplaceCategory;
use App\Models\Product;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\PendingChangeService;
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

    /** Product choices 2026-11-02: stock of a product with choices is set per choice. */
    public bool $editingHasChoices = false;

    /** Paths already saved on the product being edited. */
    public array $photos = [];

    /** New uploads, saved on submit. */
    public array $uploads = [];

    /** 2026-11-03: each "Add photo" pick lands here and is added to $uploads (a second pick never replaces the first). */
    public array $newUploads = [];

    /** What of the product being edited is waiting for approval ("Name, Photos"), shown above the form. */
    public string $editingPending = '';

    public function updatedNewUploads(): void
    {
        $this->validate(['newUploads.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096']], [], ['newUploads.*' => 'photo']);
        $room = self::MAX_PHOTOS - count($this->photos) - count($this->uploads);
        $this->uploads = array_merge($this->uploads, array_slice($this->newUploads, 0, max(0, $room)));
        if (count($this->newUploads) > $room) {
            $this->addError('uploads', 'Up to '.self::MAX_PHOTOS.' photos.');
        }
        $this->newUploads = [];
    }

    public function removeUpload(int $index): void
    {
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
    }

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
        $this->dispatch('seller-scroll-top');
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
        $this->editingHasChoices = ! empty($product->option_names);
        $this->photos = $product->images ?? [];
        // A change already waiting for approval: the form shows it, so the seller edits what they asked for.
        if ($pending = app(PendingChangeService::class)->pendingFor($product)) {
            foreach (['name' => 'name', 'description' => 'description', 'marketplace_category_id' => 'categoryId', 'images' => 'photos'] as $field => $prop) {
                if (array_key_exists($field, $pending->new_values)) {
                    $value = $pending->new_values[$field];
                    $this->{$prop} = match ($prop) { 'photos' => $value ?? [], 'categoryId' => $value, default => (string) $value };
                }
            }
            $this->editingPending = $pending->summary();
        }
        $this->creating = true;
        $this->dispatch('seller-scroll-top'); // the form opens at the top of the page
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
            if ($product->option_names) {
                unset($fields['stock']); // product choices: stock is the total of the choices
            }
            // Owner rule 2026-11-03 (PendingChangeService): price, discount, stock, on/off and the limit change at once.
            // On a LIVE item the name, photos, description and category wait for approval while customers keep seeing
            // the old ones; the item never goes off sale. An item still waiting for its first approval is edited freely.
            $changes = app(PendingChangeService::class);
            $review = $needsApproval && $product->is_approved;
            [$now, $wait] = $review ? $changes->split($product, $fields) : [$fields, []];
            if (! $review) {
                foreach (array_diff($product->images ?? [], $paths) as $removed) {
                    if (! str_contains($removed, '://')) {
                        Storage::disk('public')->delete($removed);
                    }
                }
            }
            $product->update($now);
            ActivityLogger::logModel(auth()->user(), $product, 'Seller updated product', ['fields' => array_keys($product->getChanges())]);
            if ($wait !== []) {
                $pending = $changes->request($product, $wait, auth()->user(), $store->id);
                $this->flash('Saved. Price and stock are live now. The new '.mb_strtolower($pending->summary()).' show to customers once approved; until then they see the old ones.', 'success');
            } else {
                if ($review) {
                    $changes->withdraw($product, auth()->user()); // back to the live details: nothing left to approve
                }
                $this->flash($product->is_approved ? 'Saved.' : 'Saved. It shows to customers once approved.', 'success');
            }
        } else {
            $product = Product::create($fields + ['store_id' => $store->id, 'is_approved' => ! $needsApproval, 'tax_percent' => 0]);
            ActivityLogger::logModel(auth()->user(), $product, 'Seller added product');
            $this->flash($needsApproval ? 'Product added. It shows to customers once approved.' : 'Product added.', 'success');
        }

        $this->dispatch('seller-scroll-top'); // so the seller sees "Saved."
        $this->resetForm();
    }

    /** 2026-11-03: the seller's products as a CSV that opens in Excel. */
    public function downloadSheet()
    {
        $store = $this->shop();
        $csv = app(\App\Services\Catalog\SellerProductSheet::class)->export($store);

        return response()->streamDownload(fn () => print($csv), \Illuminate\Support\Str::slug($store->name).'-products.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The sheet uploaded back: same rules as editing here (price/stock at once, a new name waits). */
    public $sheet = null;

    public function uploadSheet(): void
    {
        $this->validate(['sheet' => ['required', 'file', 'mimes:csv,txt', 'max:2048']], ['sheet.required' => 'Choose the file first.', 'sheet.mimes' => 'Upload the .csv file (in Excel: Save As, CSV).'], ['sheet' => 'file']);
        $store = $this->shop();
        $r = app(\App\Services\Catalog\SellerProductSheet::class)->import($store, auth()->user(), $this->sheet->getRealPath(), $this->shopScope($store));
        $this->sheet = null;
        $parts = array_filter([
            $r['updated'] ? $r['updated'].' changed' : null,
            $r['added'] ? $r['added'].' added' : null,
            $r['waiting'] ? $r['waiting'].' new names waiting for approval' : null,
            $r['unchanged'] ? $r['unchanged'].' unchanged' : null,
        ]);
        $this->sheetErrors = $r['errors'];
        $this->flash(($parts ? 'File read: '.implode(', ', $parts).'.' : 'Nothing changed.').($r['errors'] ? ' '.count($r['errors']).' rows were skipped (see below).' : ''), $r['errors'] && ! $parts ? 'error' : 'success');
        $this->dispatch('seller-scroll-top');
    }

    /** Row problems of the last uploaded sheet. */
    public array $sheetErrors = [];

    /** Quick stock change from the list. */
    public function setStock(int $productId, string $value): void
    {
        $product = $this->ownProduct($productId);
        if ($product->option_names) {
            $this->flash('This product has choices: set stock per choice in Units & choices.', 'error');

            return;
        }
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
        $this->reset(['editingHasChoices', 'editingId', 'name', 'description', 'categoryId', 'price', 'discountPercent', 'maxCartQuantity', 'photos', 'uploads', 'newUploads', 'editingPending']);
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

        $products = Product::where('store_id', $store->id)
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->with('category:id,name')->withCount('activeVariants')->orderBy('name')->paginate(25);
        // The newest decided-or-waiting change of each product on this page (a replaced one is never shown).
        $latestChanges = \App\Models\PendingChange::where('changeable_type', (new Product)->getMorphClass())
            ->whereIn('changeable_id', $products->pluck('id'))->where('status', '!=', \App\Models\PendingChange::REPLACED)
            ->orderBy('id')->get()->keyBy('changeable_id');

        return view('livewire.seller.products', [
            'products' => $products,
            'latestChanges' => $latestChanges,
            'categories' => MarketplaceCategory::where('module', $store->module)->where('is_active', true)->orderBy('position')->orderBy('name')->get(['id', 'name', 'parent_id']),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
            'maxPhotos' => self::MAX_PHOTOS,
        ])->layout('components.layouts.seller', ['title' => 'Products']);
    }
}
