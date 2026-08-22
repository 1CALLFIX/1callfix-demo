<?php

namespace App\Livewire\Categories;

use App\Exports\CategoriesExport;
use App\Imports\HeadingRowImport;
use App\Models\CatalogImportRun;
use App\Models\ServiceCategory;
use App\Services\Catalog\CategoryImporter;
use App\Support\Concerns\HasCsvExport;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;

// Replaces the old Categories\Index + Categories\Form pair: one screen, an
// inline "Add New" form pinned at the top, the list directly below it
// (updates live, no redirect), and edit via a modal instead of a separate
// route. Subcategories/Services get the same treatment in their own
// follow-up components.
class Manage extends Component
{
    use WithFileUploads;
    use WithPagination;
    use HasCsvExport;

    /**
     * No view-level check existed at all — only write actions checked
     * categories.manage (Phase 11 audit finding, same bug class addendum
     * #1 already fixed on 15 other screens). No separate categories.view
     * permission was ever seeded, so this reuses categories.manage.
     */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('categories.manage'), 403, 'You do not have permission to view categories.');
    }

    // --- "Add New" form (one line, pinned at top) ---
    // No sort_order field on purpose: new categories land at the end and
    // ordering is set with the Reorder controls on the list instead, so
    // there's no way for the two to disagree.
    public string $name = '';
    public $iconFile = null; // uploaded PNG/JPEG, stored on the `public` disk
    public string $module = Modules::SERVICE;
    public string $color = '#E5E7EB';
    public bool $isActive = true;

    // --- Edit modal ---
    public bool $showEditModal = false;
    public ?int $editCategoryId = null;
    public string $editName = '';
    public $editIconFile = null;          // only set when replacing the icon
    public ?string $editExistingImage = null; // current icon, kept if no new upload
    public string $editModule = Modules::SERVICE;
    public string $editColor = '#E5E7EB';
    public bool $editIsActive = true;

    // --- Delete confirmation ---
    public ?int $confirmingDeleteId = null;
    public string $deleteBlockedReason = '';

    // --- List controls ---
    public string $search = '';
    public string $filterModule = '';
    public string $filterActive = '';
    public bool $showFilters = false;
    public string $sortField = 'sort_order';
    public string $sortDirection = 'asc';
    public int $perPage = 10;
    public bool $reorderMode = false;

    public string $flashMessage = '';

    // --- Import/export ---
    public $categoriesImportFile = null;
    public bool $showCategoriesImport = false;
    public array $categoriesImportErrors = [];
    public ?array $categoriesImportRows = null;
    public ?string $categoriesImportMessage = null;
    public bool $categoriesDeactivateMissing = false;
    public ?CatalogImportRun $categoriesImportRun = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilterModule(): void
    {
        $this->resetPage();
    }

    public function updatedFilterActive(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    // ============================= Add New =============================

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'iconFile' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
            'module' => ['required', Rule::in(Modules::slugs())],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'iconFile.required' => 'Please choose an icon image (PNG or JPEG).',
            'iconFile.max' => 'The icon must be 2 MB or smaller.',
        ], [
            'iconFile' => 'icon',
        ]);

        // Catalog data (service_categories/service_subcategories/services)
        // carries no franchise/city/country column — it's shared across the
        // whole platform, not per-franchise — so there is no partial scope
        // to check against. Same global-only treatment as geography.manage.
        if (! auth()->user()->hasPermission('categories.manage')) {
            $this->addError('permission', 'You do not have permission to manage categories.');
            return;
        }

        ServiceCategory::create([
            'module' => $this->module,
            'name' => $this->name,
            'slug' => Str::slug($this->name).'-'.Str::random(4),
            'image' => $this->storeIcon($this->iconFile),
            'color' => $this->color ?: null,
            // New rows go to the end of the list; Reorder moves them from there.
            'sort_order' => (int) ServiceCategory::max('sort_order') + 1,
            'is_active' => $this->isActive,
        ]);

        $this->reset(['name', 'iconFile', 'color', 'isActive']);
        $this->color = '#E5E7EB';
        $this->flashMessage = 'Category created.';
    }

    /** Display URL for the icon already on the category being edited. */
    public function getEditExistingImageUrlProperty(): ?string
    {
        if (! $this->editExistingImage) {
            return null;
        }

        if (Str::startsWith($this->editExistingImage, ['http://', 'https://', '//', 'data:'])) {
            return $this->editExistingImage;
        }

        return Storage::disk('public')->url($this->editExistingImage);
    }

    /** Store an uploaded icon on the public disk, return its relative path. */
    private function storeIcon($file): string
    {
        return $file->store('categories', 'public');
    }

    /**
     * Delete a previously uploaded icon once it's been replaced or its
     * category removed. Guarded against legacy imported rows whose `image`
     * is a full CDN URL rather than a file we own.
     */
    private function deleteStoredIcon(?string $image): void
    {
        if (! $image || Str::startsWith($image, ['http://', 'https://', '//', 'data:'])) {
            return;
        }

        Storage::disk('public')->delete($image);
    }

    // ============================== Edit modal ==============================

    public function edit(int $categoryId): void
    {
        $category = ServiceCategory::findOrFail($categoryId);

        $this->editCategoryId = $category->id;
        $this->editName = $category->name;
        $this->editIconFile = null;
        $this->editExistingImage = $category->image;
        $this->editModule = $category->module ?? Modules::SERVICE;
        $this->editColor = $category->color ?: '#E5E7EB';
        $this->editIsActive = $category->is_active;

        $this->resetValidation();
        $this->showEditModal = true;
    }

    public function update(): void
    {
        $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            // Only required if this category has no icon yet — an edit that
            // isn't changing the picture shouldn't force a re-upload.
            'editIconFile' => [
                $this->editExistingImage ? 'nullable' : 'required',
                'image', 'mimes:png,jpg,jpeg', 'max:2048',
            ],
            'editModule' => ['required', Rule::in(Modules::slugs())],
            'editColor' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'editIconFile.required' => 'Please choose an icon image (PNG or JPEG).',
            'editIconFile.max' => 'The icon must be 2 MB or smaller.',
        ], [
            'editIconFile' => 'icon',
        ]);

        if (! auth()->user()->hasPermission('categories.manage')) {
            $this->addError('permission', 'You do not have permission to manage categories.');
            return;
        }

        $category = ServiceCategory::findOrFail($this->editCategoryId);

        $image = $category->image;
        if ($this->editIconFile) {
            $image = $this->storeIcon($this->editIconFile);
            $this->deleteStoredIcon($category->image);
        }

        $category->update([
            'module' => $this->editModule,
            'name' => $this->editName,
            'image' => $image,
            'color' => $this->editColor ?: null,
            'is_active' => $this->editIsActive,
        ]);

        $this->showEditModal = false;
        $this->editIconFile = null;
        $this->flashMessage = 'Category updated.';
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->editIconFile = null;
        $this->resetValidation();
    }

    // Inline tick/cross toggle — no page reload.
    public function toggleCategory(int $categoryId): void
    {
        if (! auth()->user()->hasPermission('categories.manage')) {
            $this->addError('permission', 'You do not have permission to manage categories.');
            return;
        }

        $category = ServiceCategory::findOrFail($categoryId);
        $category->update(['is_active' => ! $category->is_active]);
    }

    // ============================== Delete ==============================

    public function confirmDelete(int $categoryId): void
    {
        $category = ServiceCategory::withCount(['subcategories', 'services'])->findOrFail($categoryId);

        // Refuse rather than cascade: a category with live subcategories or
        // services underneath it is almost never an intentional delete, and
        // the FK on services is a plain constrained() — deleting would either
        // fail at the DB level or orphan real bookings' service references.
        // Say why instead of throwing a raw error.
        $blockers = [];
        if ($category->subcategories_count > 0) {
            $blockers[] = $category->subcategories_count.' '.Str::plural('subcategory', $category->subcategories_count);
        }
        if ($category->services_count > 0) {
            $blockers[] = $category->services_count.' '.Str::plural('service', $category->services_count);
        }

        $this->deleteBlockedReason = $blockers
            ? 'This category still has '.implode(' and ', $blockers).' attached. Move or delete those first.'
            : '';

        $this->confirmingDeleteId = $categoryId;
    }

    public function deleteCategory(): void
    {
        if (! $this->confirmingDeleteId || $this->deleteBlockedReason) {
            return;
        }

        if (! auth()->user()->hasPermission('categories.manage')) {
            $this->addError('permission', 'You do not have permission to manage categories.');
            $this->confirmingDeleteId = null;
            return;
        }

        $category = ServiceCategory::findOrFail($this->confirmingDeleteId);
        $image = $category->image;
        $category->delete();
        $this->deleteStoredIcon($image);

        $this->confirmingDeleteId = null;
        $this->flashMessage = 'Category deleted.';
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
        $this->deleteBlockedReason = '';
    }

    // ============================== Sorting ==============================

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        // Reordering is only meaningful against the list's own running order;
        // under an ID sort the up/down arrows would appear to move rows at
        // random, so drop out of reorder mode rather than show a broken control.
        if ($field !== 'sort_order') {
            $this->reorderMode = false;
        }

        $this->resetPage();
    }

    // ============================== Reorder ==============================

    public function toggleReorder(): void
    {
        $this->reorderMode = ! $this->reorderMode;

        if ($this->reorderMode) {
            // Reorder implies showing the list in its actual running order.
            $this->sortField = 'sort_order';
            $this->sortDirection = 'asc';
        }
    }

    public function moveUp(int $categoryId): void
    {
        $this->swapWithNeighbour($categoryId, -1);
    }

    public function moveDown(int $categoryId): void
    {
        $this->swapWithNeighbour($categoryId, 1);
    }

    /**
     * Swap a category's position with the row above/below it *in the list as
     * currently filtered*. Filtering by module and reordering within that
     * module is the normal way to arrange one vertical's categories, so the
     * neighbour is taken from the filtered set rather than the global one.
     */
    private function swapWithNeighbour(int $categoryId, int $offset): void
    {
        if (! auth()->user()->hasPermission('categories.manage')) {
            $this->addError('permission', 'You do not have permission to reorder categories.');
            return;
        }

        $this->normalizeSortOrder();

        $ordered = $this->baseQuery()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'sort_order']);

        $index = $ordered->search(fn ($row) => $row->id === $categoryId);
        if ($index === false) {
            return;
        }

        $target = $ordered->get($index + $offset);
        if (! $target) {
            return; // already at the top/bottom of the list
        }

        $current = $ordered->get($index);

        DB::transaction(function () use ($current, $target) {
            ServiceCategory::whereKey($current->id)->update(['sort_order' => $target->sort_order]);
            ServiceCategory::whereKey($target->id)->update(['sort_order' => $current->sort_order]);
        });
    }

    /**
     * sort_order defaults to 0 for every row, and imported rows can share
     * values too — swapping two identical numbers is a no-op, so the arrows
     * would silently do nothing. Collapse to a clean 1..N sequence (globally,
     * not per-filter, so positions stay stable across different filters)
     * the first time an actual move is attempted.
     */
    private function normalizeSortOrder(): void
    {
        $all = ServiceCategory::orderBy('sort_order')->orderBy('id')->get(['id', 'sort_order']);

        $needsNormalising = $all->pluck('sort_order')->duplicates()->isNotEmpty()
            || $all->contains(fn ($row) => (int) $row->sort_order === 0);

        if (! $needsNormalising) {
            return;
        }

        DB::transaction(function () use ($all) {
            foreach ($all->values() as $position => $row) {
                ServiceCategory::whereKey($row->id)->update(['sort_order' => $position + 1]);
            }
        });
    }

    // ============================= Import/export =============================

    public function exportCategories()
    {
        return Excel::download(new CategoriesExport, 'categories-'.now()->format('Y-m-d').'.xlsx');
    }

    public function downloadCategoriesTemplate()
    {
        return Excel::download(new CategoriesExport(templateOnly: true), 'categories-template.xlsx');
    }

    /**
     * Export Everywhere session, Part 1 — distinct from exportCategories()
     * above: that one is the full-catalog xlsx backup (re-importable via
     * CategoryImporter, always the whole table). This is the CURRENT
     * FILTERED VIEW as a plain CSV — respects $search/$filterModule/
     * $filterActive exactly as baseQuery() already applies them for the
     * on-screen list. Categories carry no franchise/zone column (global
     * catalog — see save()'s own comment), so there's no
     * AuthorizationService::scopeQuery() call here; nothing to scope.
     */
    public function exportCategoriesCsv()
    {
        return $this->streamCsvExport(
            'categories-filtered-'.now()->format('Y-m-d-His').'.csv',
            $this->baseQuery()->withCount(['subcategories', 'services']),
            ['id', 'name', 'module', 'is_active', 'subcategories_count', 'services_count', 'sort_order', 'created_at'],
            fn (ServiceCategory $c) => [$c->id, $c->name, $c->module, $c->is_active ? 1 : 0, $c->subcategories_count, $c->services_count, $c->sort_order, $c->created_at],
        );
    }

    public function toggleCategoriesImport(): void
    {
        $this->showCategoriesImport = ! $this->showCategoriesImport;
        $this->resetCategoriesImportState();
    }

    private function resetCategoriesImportState(): void
    {
        $this->categoriesImportFile = null;
        $this->categoriesImportErrors = [];
        $this->categoriesImportRows = null;
        $this->categoriesImportMessage = null;
        $this->categoriesDeactivateMissing = false;
        $this->categoriesImportRun = null;
    }

    /**
     * VALIDATE -> RELATIONSHIP CHECK -> DUPLICATE CHECK -> PRICE/IMAGE
     * CHECK -> PREVIEW, all via CategoryImporter (mission Phase 14 —
     * Master Catalog Import). Field-level rules live in the importer now,
     * not here, so Categories/Subcategories/Services all go through the
     * same real pipeline instead of three near-identical inline copies.
     */
    public function validateCategoriesImport(): void
    {
        $this->categoriesImportErrors = [];
        $this->categoriesImportRows = null;
        $this->categoriesImportMessage = null;
        $this->categoriesImportRun = null;

        $this->validate(['categoriesImportFile' => ['required', 'file', 'mimes:xlsx,xls,csv']]);

        $reader = new HeadingRowImport;
        Excel::import($reader, $this->categoriesImportFile->getRealPath());

        $result = (new CategoryImporter)->validateRows($reader->rows);

        if (! empty($result['errors'])) {
            $this->categoriesImportErrors = $result['errors'];
            return;
        }

        $this->categoriesImportRows = $result['previewRows'];
    }

    /** CONFIRM -> TRANSACTION-SAFE IMPORT -> IMPORT REPORT. */
    public function commitCategoriesImport(): void
    {
        if (empty($this->categoriesImportRows)) {
            return;
        }

        if (! auth()->user()->hasPermission('categories.manage')) {
            $this->categoriesImportErrors = [['row' => '-', 'field' => 'permission', 'message' => 'You do not have permission to import categories.']];
            return;
        }

        $fileName = $this->categoriesImportFile?->getClientOriginalName();

        $this->categoriesImportRun = (new CategoryImporter)->commit(
            $this->categoriesImportRows, auth()->user(), $fileName, $this->categoriesDeactivateMissing
        );

        if ($this->categoriesImportRun->status === 'failed') {
            $this->categoriesImportErrors = [['row' => '-', 'field' => 'commit', 'message' => 'Import failed, nothing was saved.']];
            return;
        }

        $this->categoriesImportMessage = 'Import complete.';
        $this->categoriesImportRows = null;
        $this->categoriesImportFile = null;
    }

    // ============================== Shared helpers ==============================

    /** Search + filters, shared by the list query and the reorder neighbour lookup. */
    private function baseQuery()
    {
        return ServiceCategory::query()
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->when($this->filterModule !== '', fn ($q) => $q->where('module', $this->filterModule))
            ->when($this->filterActive !== '', fn ($q) => $q->where('is_active', $this->filterActive === 'active'));
    }

    public function render()
    {
        $categories = $this->baseQuery()
            ->withCount(['subcategories', 'services'])
            ->orderBy($this->sortField, $this->sortDirection)
            ->orderBy('id')
            ->paginate($this->perPage);

        return view('livewire.categories.manage', [
            'categories' => $categories,
            'modules' => Modules::options(),
        ])->layout('layouts.admin', ['title' => 'Categories']);
    }
}
