<?php

namespace App\Livewire\Products;

use App\Exports\ProductsExport;
use App\Imports\HeadingRowImport;
use App\Models\CatalogImportRun;
use App\Models\MarketplaceCategory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\AuthorizationService;
use App\Services\Catalog\ProductImporter;
use App\Support\Concerns\HasCsvExport;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Phase 24 (Marketplace Foundation) admin screen. `Product` has no
 * franchise/zone column of its own -- scoped through its owning `store`
 * relation, same dotted-relation convention `AuthorizationService::
 * scopeQuery()` already supports for every other cross-relation scope hint
 * in this codebase.
 */
class Manage extends Component
{
    use WithPagination;
    use WithFileUploads;
    use HasCsvExport;

    public string $search = '';
    public ?int $storeIdFilter = null;

    // --- Import/export (Export Everywhere + Import Where It's Safe session) ---
    public $productsImportFile = null;
    public bool $showProductsImport = false;
    public array $productsImportErrors = [];
    public ?array $productsImportRows = null;
    public ?string $productsImportMessage = null;
    public bool $productsDeactivateMissing = false;
    public ?CatalogImportRun $productsImportRun = null;

    public ?int $storeId = null;
    public ?int $marketplaceCategoryId = null;
    public string $name = '';
    public string $price = '0';
    public string $stock = '0';

    public bool $showEditModal = false;
    public ?int $editProductId = null;
    public string $editName = '';
    public string $editPrice = '0';
    public string $editStock = '0';
    public bool $editIsActive = true;
    public bool $editIsApproved = true;

    public string $newVariantName = '';
    public string $newVariantPriceOverride = '';
    public string $newVariantStock = '0';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('products.manage'), 403, 'You do not have permission to manage products.');
    }

    private function scopeColumns(): array
    {
        return [
            'zone_id' => 'store.zone_id', 'franchise_id' => 'store.franchise_id',
            'city_id' => 'store.franchise.city_id', 'country_id' => 'store.franchise.country_id',
        ];
    }

    private function scopedProductsQuery()
    {
        return app(AuthorizationService::class)
            ->scopeQuery(Product::query(), auth()->user(), 'products.manage', $this->scopeColumns())
            ->with(['store', 'category']);
    }

    public function createProduct(): void
    {
        $this->validate([
            'storeId' => 'required|exists:stores,id',
            'marketplaceCategoryId' => 'nullable|exists:marketplace_categories,id',
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
        ]);

        $store = Store::with('franchise')->findOrFail($this->storeId);

        // Same gap as the other catalog Manage screens -- the stores
        // dropdown is unscoped, so a zone-scoped actor holding
        // products.manage only in Zone A could otherwise create a product
        // under any other zone/franchise's store platform-wide.
        // edit/saveEdit/addVariant were already safe (scopedProductsQuery()
        // 404s out-of-scope); create had no check.
        if (! auth()->user()->hasPermission('products.manage', [
            'zone_id' => $store->zone_id, 'franchise_id' => $store->franchise_id,
            'city_id' => $store->franchise?->city_id, 'country_id' => $store->franchise?->country_id,
        ])) {
            $this->addError('storeId', 'You do not have permission to add a product to this store.');
            return;
        }

        Product::create([
            'store_id' => $this->storeId, 'marketplace_category_id' => $this->marketplaceCategoryId,
            'name' => $this->name, 'price' => $this->price, 'stock' => $this->stock,
            'is_active' => true, 'is_approved' => true,
        ]);

        $this->reset(['storeId', 'marketplaceCategoryId', 'name', 'price', 'stock']);
        session()->flash('message', 'Product created.');
    }

    public function editProduct(int $productId): void
    {
        $product = $this->scopedProductsQuery()->with('variants')->find($productId);
        abort_if(! $product, 404);

        $this->editProductId = $product->id;
        $this->editName = $product->name;
        $this->editPrice = (string) $product->price;
        $this->editStock = (string) $product->stock;
        $this->editIsActive = $product->is_active;
        $this->editIsApproved = $product->is_approved;
        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
    }

    public function saveEdit(): void
    {
        $product = $this->scopedProductsQuery()->find($this->editProductId);
        abort_if(! $product, 404);

        $this->validate([
            'editName' => 'required|string|max:255',
            'editPrice' => 'required|numeric|min:0',
            'editStock' => 'required|integer|min:0',
        ]);

        $product->update([
            'name' => $this->editName, 'price' => $this->editPrice, 'stock' => $this->editStock,
            'is_active' => $this->editIsActive, 'is_approved' => $this->editIsApproved,
        ]);

        session()->flash('message', 'Product updated.');
    }

    public function addVariant(): void
    {
        $product = $this->scopedProductsQuery()->find($this->editProductId);
        abort_if(! $product, 404);

        $this->validate([
            'newVariantName' => 'required|string|max:255',
            'newVariantPriceOverride' => 'nullable|numeric|min:0',
            'newVariantStock' => 'required|integer|min:0',
        ]);

        ProductVariant::create([
            'product_id' => $product->id, 'name' => $this->newVariantName,
            'price_override' => $this->newVariantPriceOverride !== '' ? $this->newVariantPriceOverride : null,
            'stock' => $this->newVariantStock, 'is_active' => true,
        ]);

        $this->reset(['newVariantName', 'newVariantPriceOverride', 'newVariantStock']);
        session()->flash('message', 'Variant added.');
    }

    public function getEditingProductProperty(): ?Product
    {
        if (! $this->editProductId) {
            return null;
        }

        return $this->scopedProductsQuery()->with('variants')->find($this->editProductId);
    }

    // ============================= Import/export =============================

    public function exportProductsTemplate()
    {
        return Excel::download(new ProductsExport(templateOnly: true), 'products-template.xlsx');
    }

    public function toggleProductsImport(): void
    {
        $this->showProductsImport = ! $this->showProductsImport;
        $this->productsImportFile = null;
        $this->productsImportErrors = [];
        $this->productsImportRows = null;
        $this->productsImportMessage = null;
        $this->productsDeactivateMissing = false;
        $this->productsImportRun = null;
    }

    /**
     * VALIDATE -> RELATIONSHIP CHECK -> SCOPE CHECK -> DUPLICATE CHECK ->
     * PREVIEW, via ProductImporter (extends the same CatalogImporter engine
     * Categories/Subcategories/Services already use — see its own
     * docblock). The acting user is threaded through the constructor so
     * processRow() can enforce the SAME per-store scope check
     * createProduct() above already does (a franchise-scoped actor can't
     * import into another franchise's store) — flagged as a per-row error
     * at PREVIEW time, not discovered only at commit.
     *
     * PARTIAL SUCCESS, deliberately (mission spec: "invalid rows are
     * skipped and reported, not silently dropped or allowed to fail the
     * whole batch") — unlike Categories/Subcategories/Services'
     * validateXImport() siblings, which discard previewRows and block the
     * WHOLE file the moment any row errors, this sets BOTH errors AND
     * previewRows whenever each is non-empty. x-import-panel already
     * renders the two sections independently (errors table + preview/
     * commit table can both be present at once) — no change needed there,
     * this is the only piece of the flow that decides which is shown.
     */
    public function validateProductsImport(): void
    {
        $this->productsImportErrors = [];
        $this->productsImportRows = null;
        $this->productsImportMessage = null;
        $this->productsImportRun = null;

        $this->validate(['productsImportFile' => ['required', 'file', 'mimes:xlsx,xls,csv']]);

        $reader = new HeadingRowImport;
        Excel::import($reader, $this->productsImportFile->getRealPath());

        $result = (new ProductImporter(auth()->user()))->validateRows($reader->rows);

        $this->productsImportErrors = $result['errors'];
        $this->productsImportRows = $result['previewRows'] ?: null; // null (not []) so the panel's "nothing to commit" state renders correctly when every row failed
    }

    /** CONFIRM -> TRANSACTION-SAFE IMPORT -> IMPORT REPORT. Only ever commits the rows that survived validation (productsImportRows) — the rejected ones (productsImportErrors) were never in that array, so they're structurally incapable of being written, not just skipped by convention. */
    public function commitProductsImport(): void
    {
        if (empty($this->productsImportRows)) {
            return;
        }

        if (! auth()->user()->hasPermissionAnywhere('products.manage')) {
            $this->productsImportErrors = [['row' => '-', 'field' => 'permission', 'message' => 'You do not have permission to import products.']];
            return;
        }

        $fileName = $this->productsImportFile?->getClientOriginalName();

        $this->productsImportRun = (new ProductImporter(auth()->user()))->commit(
            $this->productsImportRows, auth()->user(), $fileName, $this->productsDeactivateMissing
        );

        if ($this->productsImportRun->status === 'failed') {
            $this->productsImportErrors = [['row' => '-', 'field' => 'commit', 'message' => 'Import failed, nothing was saved.']];
            return;
        }

        $this->productsImportMessage = 'Import complete.';
        $this->productsImportRows = null;
        $this->productsImportFile = null;
    }

    /** Scope + the screen's own search/store filters, in one place — render() paginates it, exportProductsCsv() streams every matching row unpaginated. Keeps the two from ever disagreeing on what "the current view" means. */
    private function filteredProductsQuery()
    {
        return $this->scopedProductsQuery()
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->when($this->storeIdFilter, fn ($q) => $q->where('store_id', $this->storeIdFilter));
    }

    public function render()
    {
        $products = $this->filteredProductsQuery()
            ->latest('id')
            ->paginate(20);

        return view('livewire.products.manage', [
            'products' => $products,
            'stores' => Store::where('is_active', true)->orderBy('name')->get(),
            'categories' => MarketplaceCategory::where('is_active', true)->orderBy('name')->get(),
        ])->layout('layouts.admin', ['title' => 'Products']);
    }

    /** Export Everywhere session, Part 1 — current filtered + scoped view as CSV. */
    public function exportProductsCsv()
    {
        return $this->streamCsvExport(
            'products-filtered-'.now()->format('Y-m-d-His').'.csv',
            $this->filteredProductsQuery()->with(['store', 'category']),
            ['id', 'name', 'store', 'category', 'price', 'discount_percent', 'stock', 'is_active', 'is_approved', 'created_at'],
            fn (Product $p) => [$p->id, $p->name, $p->store?->name, $p->category?->name, $p->price, $p->discount_percent, $p->stock, $p->is_active ? 1 : 0, $p->is_approved ? 1 : 0, $p->created_at],
        );
    }
}
