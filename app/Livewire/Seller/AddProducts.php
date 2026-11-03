<?php

namespace App\Livewire\Seller;

use App\Jobs\ReadProductPhotoJob;
use App\Livewire\Seller\Concerns\InteractsWithSeller;
use App\Models\CatalogItem;
use App\Models\MarketplaceCategory;
use App\Models\Product;
use App\Models\ProductPhotoScan;
use App\Services\ActivityLogger;
use App\Services\Ai\ProductPhotoReader;
use App\Support\MarketplaceSettings;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Owner 2026-11-03: "every shop has many products; if adding them is hard they will not join". Two quick ways to add
 * many products at once, both ending in one list the seller checks (price, stock) before anything is added:
 *  - From our list: pick from the 1CallFix master product list (catalog_items) by category, search, tick;
 *  - From a photo: photograph a price list, bill, shelf or menu; it is read in the background (ReadProductPhotoJob).
 * Added products follow the usual rules: a new item waits for approval when Shop Settings says so; blocked words are
 * refused; a name the shop already has is skipped.
 */
class AddProducts extends Component
{
    use InteractsWithSeller;
    use WithFileUploads;

    private const MAX_ROWS = 100;
    private const MAX_PHOTOS = 3;

    public string $tab = 'list';

    // ── from our list ──
    public string $category = '';
    public string $subCategory = '';
    public string $search = '';

    // ── from a photo ──
    public array $photos = [];
    public ?int $scanId = null;

    /** The list to check: key => [name, category_id, price, stock, unit_value, unit, from]. */
    public array $rows = [];

    public string $flashMessage = '';
    public string $flashType = 'success';

    public function updatedCategory(): void
    {
        $this->subCategory = '';
    }

    /** Tick an item of the master list: it joins the list to check. */
    public function pickItem(int $id): void
    {
        $item = CatalogItem::where('is_active', true)->find($id);
        if (! $item) {
            return;
        }
        $key = 'c'.$item->id;
        if (isset($this->rows[$key])) {
            unset($this->rows[$key]);

            return;
        }
        if (count($this->rows) >= self::MAX_ROWS) {
            $this->flash('Up to '.self::MAX_ROWS.' products at a time. Add these first.', 'error');

            return;
        }
        $this->rows[$key] = ['name' => $item->name, 'category_id' => $item->marketplace_category_id, 'price' => '', 'stock' => '', 'unit_value' => '', 'unit' => '', 'from' => 'list'];
    }

    public function removeRow(string $key): void
    {
        unset($this->rows[$key]);
    }

    public function clearRows(): void
    {
        $this->rows = [];
    }

    /** Photos are re-saved as JPEG of at most 2000 px, then read in the background. */
    public function readPhotos(): void
    {
        $store = $this->shop();
        $this->validate([
            'photos' => ['required', 'array', 'min:1', 'max:'.self::MAX_PHOTOS],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ], ['photos.required' => 'Choose a photo first.'], ['photos.*' => 'photo']);
        if (! MarketplaceSettings::on('marketplace.photo_list_enabled') || ! ProductPhotoReader::configured()) {
            $this->flash('Adding from a photo is not switched on yet. Use "From our list" or add them one by one.', 'error');

            return;
        }
        $limit = (int) MarketplaceSettings::get('marketplace.photo_list_daily_limit');
        $today = ProductPhotoScan::where('store_id', $store->id)->where('created_at', '>=', now()->startOfDay())->count();
        if ($today >= $limit) {
            $this->flash("Your shop has read {$limit} photos today, the most for one day. Try again tomorrow.", 'error');

            return;
        }

        $paths = [];
        foreach ($this->photos as $photo) {
            $jpeg = $this->downsize((string) file_get_contents($photo->getRealPath()));
            if ($jpeg === null) {
                $this->addError('photos', 'One photo could not be opened. Try another photo.');

                return;
            }
            $path = 'photo-scans/'.$store->id.'/'.Str::uuid().'.jpg';
            Storage::disk('local')->put($path, $jpeg);
            $paths[] = $path;
        }
        $scan = ProductPhotoScan::create(['store_id' => $store->id, 'user_id' => auth()->id(), 'status' => 'waiting', 'photo_paths' => $paths]);
        ReadProductPhotoJob::dispatch($scan->id);
        ActivityLogger::logModel(auth()->user(), $store, 'Seller sent photos to read into products', ['scan_id' => $scan->id, 'photos' => count($paths)]);
        $this->scanId = $scan->id;
        $this->photos = [];
        $this->flashMessage = '';
    }

    /** Polled while a photo is being read; the items found join the list to check. */
    public function checkScan(): void
    {
        if (! $this->scanId) {
            return;
        }
        $scan = ProductPhotoScan::where('store_id', $this->shop()->id)->find($this->scanId);
        if (! $scan || ! $scan->isFinished()) {
            return;
        }
        $this->scanId = null;
        if ($scan->status === 'failed' || ($scan->items ?? []) === []) {
            $this->flash($scan->error ?: 'No products could be read in this photo.', 'error');

            return;
        }
        $added = 0;
        foreach ($scan->items as $i => $item) {
            if (count($this->rows) >= self::MAX_ROWS) {
                break;
            }
            $this->rows['p'.$scan->id.'_'.$i] = [
                'name' => $item['name'], 'category_id' => $item['category_id'] ?? null,
                'price' => $item['price'] !== null ? (string) $item['price'] : '', 'stock' => $item['quantity'] !== null ? (string) $item['quantity'] : '',
                'unit_value' => $item['unit_value'] !== null ? (string) (float) $item['unit_value'] : '', 'unit' => (string) ($item['unit'] ?? ''), 'from' => 'photo',
            ];
            $added++;
        }
        $this->flash("Read {$added} products from the photo. Check the names and prices below, fill in what is missing, then add them.", 'success');
    }

    /** Adds every checked row as a product of this shop. */
    public function addAll(): void
    {
        $store = $this->shop();
        if ($this->rows === []) {
            $this->flash('Nothing to add yet.', 'error');

            return;
        }
        $units = MarketplaceSettings::lines('marketplace.product_units');
        $rules = [];
        foreach (array_keys($this->rows) as $key) {
            $rules["rows.$key.name"] = ['required', 'string', 'min:2', 'max:150'];
            $rules["rows.$key.price"] = ['required', 'numeric', 'min:1', 'max:10000000'];
            $rules["rows.$key.stock"] = ['required', 'integer', 'min:0', 'max:1000000'];
            $rules["rows.$key.unit_value"] = ['nullable', 'numeric', 'gt:0', 'max:100000', "required_with:rows.$key.unit"];
            $rules["rows.$key.unit"] = ['nullable', 'in:'.implode(',', $units), "required_with:rows.$key.unit_value"];
        }
        $this->validate($rules, [], collect(array_keys($this->rows))->flatMap(fn ($k) => [
            "rows.$k.name" => 'name', "rows.$k.price" => 'price', "rows.$k.stock" => 'stock', "rows.$k.unit_value" => 'amount', "rows.$k.unit" => 'unit',
        ])->all());

        $validCategories = MarketplaceCategory::where('module', $store->module)->where('is_active', true)->pluck('id')->all();
        $needsApproval = MarketplaceSettings::on('marketplace.seller_products_need_approval', $this->shopScope($store));
        $existing = Product::where('store_id', $store->id)->pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->flip();
        $added = [];
        $skipped = [];
        foreach ($this->rows as $key => $row) {
            $name = trim($row['name']);
            if (($word = MarketplaceSettings::blockedWordIn($name)) !== null) {
                $skipped[] = "{$name} (“{$word}” cannot be sold)";

                continue;
            }
            if ($existing->has(mb_strtolower($name))) {
                $skipped[] = "{$name} (already in your shop)";
                unset($this->rows[$key]);

                continue;
            }
            $product = Product::create([
                'store_id' => $store->id,
                'marketplace_category_id' => in_array((int) $row['category_id'], $validCategories, true) ? (int) $row['category_id'] : null,
                'name' => $name,
                'price' => round((float) $row['price'], 2),
                'stock' => (int) $row['stock'],
                'unit_value' => $row['unit_value'] === '' ? null : round((float) $row['unit_value'], 3),
                'unit' => $row['unit'] === '' ? null : $row['unit'],
                'tax_percent' => 0,
                'is_active' => true,
                'is_approved' => ! $needsApproval,
            ]);
            $existing->put(mb_strtolower($name), true);
            $added[] = $product->id;
            unset($this->rows[$key]);
        }
        ActivityLogger::logModel(auth()->user(), $store, 'Seller added products in bulk', ['added' => count($added), 'skipped' => count($skipped)]);

        $message = count($added).' products added'.($needsApproval && $added ? '. They show to customers once approved.' : '.');
        if ($skipped) {
            $message .= ' Not added: '.implode(', ', array_slice($skipped, 0, 10)).(count($skipped) > 10 ? ' and '.(count($skipped) - 10).' more' : '').'.';
        }
        $this->flash($message, $added ? 'success' : 'error');
        $this->dispatch('seller-scroll-top');
    }

    /** JPEG, longest side at most 2000 px (the photo reader's sweet spot), or null when it is not a photo. */
    private function downsize(string $bytes): ?string
    {
        $img = @imagecreatefromstring($bytes);
        if (! $img) {
            return null;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, 2000 / max($w, $h));
        if ($scale < 1) {
            $resized = imagescale($img, (int) round($w * $scale), (int) round($h * $scale));
            imagedestroy($img);
            $img = $resized;
        }
        ob_start();
        imagejpeg($img, null, 85);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    private function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }

    public function render()
    {
        $store = $this->shop();
        $items = CatalogItem::where('module', $store->module)->where('is_active', true);
        $categories = (clone $items)->select('category')->distinct()->orderBy('category')->pluck('category');
        $subCategories = $this->category === '' ? collect() : (clone $items)->where('category', $this->category)->select('sub_category')->distinct()->orderBy('sub_category')->pluck('sub_category');
        $list = ($this->category === '' && trim($this->search) === '') ? collect() : (clone $items)
            ->when($this->category !== '', fn ($q) => $q->where('category', $this->category))
            ->when($this->subCategory !== '', fn ($q) => $q->where('sub_category', $this->subCategory))
            ->when(trim($this->search) !== '', fn ($q) => $q->where('name', 'like', '%'.trim($this->search).'%'))
            ->orderBy('sub_category')->orderBy('name')->limit(150)->get();
        $have = Product::where('store_id', $store->id)->pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->flip();

        return view('livewire.seller.add-products', [
            'categories' => $categories,
            'subCategories' => $subCategories,
            'list' => $list,
            'have' => $have,
            'shopCategories' => MarketplaceCategory::with('parent')->where('module', $store->module)->where('is_active', true)->orderBy('name')->get()
                ->mapWithKeys(fn ($c) => [$c->id => ($c->parent ? $c->parent->name.' › ' : '').$c->name])->sort(),
            'units' => MarketplaceSettings::lines('marketplace.product_units'),
            'photoOn' => MarketplaceSettings::on('marketplace.photo_list_enabled') && ProductPhotoReader::configured(),
            'scan' => $this->scanId ? ProductPhotoScan::find($this->scanId) : null,
            'maxPhotos' => self::MAX_PHOTOS,
        ])->layout('components.layouts.seller', ['title' => 'Add many products']);
    }
}
