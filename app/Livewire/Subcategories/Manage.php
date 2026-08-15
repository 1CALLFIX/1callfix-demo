<?php

namespace App\Livewire\Subcategories;

use App\Exports\SubcategoriesExport;
use App\Imports\HeadingRowImport;
use App\Models\CatalogImportRun;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use App\Services\Catalog\SubcategoryImporter;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;

// Same one-screen pattern as Categories\Manage: inline add form pinned at the
// top, live list below, edit in a modal, single route. Replaces the old
// Subcategories\Form (create/edit pages).
//
// Reorder uses one flat sort_order across every subcategory, identical to
// Categories and Services: the arrows swap the clicked row with its immediate
// neighbour in the currently-filtered list, regardless of parent category.
// (This was originally scoped per parent category, on the reasoning that a
// subcategory's position only matters within its own category — but that made
// the arrows silently skip rows whenever a neighbour belonged elsewhere, and
// left this screen behaving differently from the other two for no visible
// reason.)
//
// Module isn't stored here at all — it's inherited from the parent category,
// shown read-only, and filterable via that relation.
class Manage extends Component
{
    use WithFileUploads;
    use WithPagination;

    /**
     * No view-level check existed at all — only write actions checked
     * categories.manage (subcategories reuse the categories permission
     * slug, same as every write action in this file) (Phase 11 audit
     * finding, same bug class addendum #1 already fixed on 15 other
     * screens).
     */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('categories.manage'), 403, 'You do not have permission to view subcategories.');
    }

    // --- "Add New" form ---
    public string $name = '';
    public $iconFile = null;
    public string $categoryId = '';
    public bool $isActive = true;

    // --- Edit modal ---
    public bool $showEditModal = false;
    public ?int $editSubcategoryId = null;
    public string $editName = '';
    public $editIconFile = null;
    public ?string $editExistingImage = null;
    public string $editCategoryId = '';
    public bool $editIsActive = true;

    // --- Delete confirmation ---
    public ?int $confirmingDeleteId = null;
    public string $deleteBlockedReason = '';

    // --- List controls ---
    public string $search = '';
    public string $filterModule = '';
    public string $filterCategory = '';
    public string $filterActive = '';
    public bool $showFilters = false;
    public string $sortField = 'sort_order';
    public string $sortDirection = 'asc';
    public int $perPage = 10;
    public bool $reorderMode = false;

    public string $flashMessage = '';

    // --- Import/export ---
    public $importFile = null;
    public bool $showImport = false;
    public array $importErrors = [];
    public ?array $importRows = null;
    public ?string $importMessage = null;
    public bool $deactivateMissing = false;
    public ?CatalogImportRun $importRun = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilterModule(): void
    {
        // A category filter from a different module would contradict the new
        // module filter and silently return nothing — clear it.
        if ($this->filterCategory !== '') {
            $category = ServiceCategory::find($this->filterCategory);
            if ($this->filterModule !== '' && $category && $category->module !== $this->filterModule) {
                $this->filterCategory = '';
            }
        }

        $this->resetPage();
    }

    public function updatedFilterCategory(): void
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
            'categoryId' => ['required', 'exists:service_categories,id'],
        ], [
            'iconFile.required' => 'Please choose an icon image (PNG or JPEG).',
            'iconFile.max' => 'The icon must be 2 MB or smaller.',
            'categoryId.required' => 'Please pick a parent category.',
        ], [
            'iconFile' => 'icon',
            'categoryId' => 'category',
        ]);

        // Subcategories have no permission of their own — the seeded
        // categories.manage label is explicitly "Manage categories &
        // subcategories", so it's the one permission meant to govern both,
        // not a gap to fill with a new slug. Same global-only scope as
        // Categories\Manage (service_subcategories carries no franchise
        // column either).
        if (! auth()->user()->hasPermission('categories.manage')) {
            $this->addError('permission', 'You do not have permission to manage subcategories.');
            return;
        }

        ServiceSubcategory::create([
            'category_id' => $this->categoryId,
            'name' => $this->name,
            'slug' => Str::slug($this->name).'-'.Str::random(4),
            'image' => $this->storeIcon($this->iconFile),
            // New rows go to the end of the list; Reorder moves them from there.
            'sort_order' => (int) ServiceSubcategory::max('sort_order') + 1,
            'is_active' => $this->isActive,
        ]);

        $this->reset(['name', 'iconFile', 'isActive']);
        $this->flashMessage = 'Subcategory created.';
    }

    /** Display URL for the icon already on the subcategory being edited. */
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

    private function storeIcon($file): string
    {
        return $file->store('subcategories', 'public');
    }

    private function deleteStoredIcon(?string $image): void
    {
        if (! $image || Str::startsWith($image, ['http://', 'https://', '//', 'data:'])) {
            return;
        }

        Storage::disk('public')->delete($image);
    }

    // ============================== Edit modal ==============================

    public function edit(int $subcategoryId): void
    {
        $sub = ServiceSubcategory::findOrFail($subcategoryId);

        $this->editSubcategoryId = $sub->id;
        $this->editName = $sub->name;
        $this->editIconFile = null;
        $this->editExistingImage = $sub->image;
        $this->editCategoryId = (string) $sub->category_id;
        $this->editIsActive = $sub->is_active;

        $this->resetValidation();
        $this->showEditModal = true;
    }

    public function update(): void
    {
        $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editIconFile' => [
                $this->editExistingImage ? 'nullable' : 'required',
                'image', 'mimes:png,jpg,jpeg', 'max:2048',
            ],
            'editCategoryId' => ['required', 'exists:service_categories,id'],
        ], [
            'editIconFile.required' => 'Please choose an icon image (PNG or JPEG).',
            'editIconFile.max' => 'The icon must be 2 MB or smaller.',
        ], [
            'editIconFile' => 'icon',
            'editCategoryId' => 'category',
        ]);

        if (! auth()->user()->hasPermission('categories.manage')) {
            $this->addError('permission', 'You do not have permission to manage subcategories.');
            return;
        }

        $sub = ServiceSubcategory::findOrFail($this->editSubcategoryId);

        $image = $sub->image;
        if ($this->editIconFile) {
            $image = $this->storeIcon($this->editIconFile);
            $this->deleteStoredIcon($sub->image);
        }

        // sort_order is one flat sequence across every subcategory, so moving
        // one to a different category leaves its position untouched.
        $sub->update([
            'category_id' => $this->editCategoryId,
            'name' => $this->editName,
            'image' => $image,
            'is_active' => $this->editIsActive,
        ]);

        $this->showEditModal = false;
        $this->editIconFile = null;
        $this->flashMessage = 'Subcategory updated.';
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->editIconFile = null;
        $this->resetValidation();
    }

    public function toggleSubcategory(int $subcategoryId): void
    {
        if (! auth()->user()->hasPermission('categories.manage')) {
            $this->addError('permission', 'You do not have permission to manage subcategories.');
            return;
        }

        $sub = ServiceSubcategory::findOrFail($subcategoryId);
        $sub->update(['is_active' => ! $sub->is_active]);
    }

    // ============================== Delete ==============================

    public function confirmDelete(int $subcategoryId): void
    {
        $sub = ServiceSubcategory::withCount('services')->findOrFail($subcategoryId);

        // Same reasoning as Categories: services point at this row, and real
        // bookings point at those services. Refuse with a reason rather than
        // cascade or blow up on the FK.
        $this->deleteBlockedReason = $sub->services_count > 0
            ? 'This subcategory still has '.$sub->services_count.' '.Str::plural('service', $sub->services_count).' attached. Move or delete those first.'
            : '';

        $this->confirmingDeleteId = $subcategoryId;
    }

    public function deleteSubcategory(): void
    {
        if (! $this->confirmingDeleteId || $this->deleteBlockedReason) {
            return;
        }

        if (! auth()->user()->hasPermission('categories.manage')) {
            $this->addError('permission', 'You do not have permission to manage subcategories.');
            $this->confirmingDeleteId = null;
            return;
        }

        $sub = ServiceSubcategory::findOrFail($this->confirmingDeleteId);
        $image = $sub->image;
        $sub->delete();
        $this->deleteStoredIcon($image);

        $this->confirmingDeleteId = null;
        $this->flashMessage = 'Subcategory deleted.';
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
            $this->sortField = 'sort_order';
            $this->sortDirection = 'asc';
        }
    }

    public function moveUp(int $subcategoryId): void
    {
        $this->swapWithNeighbour($subcategoryId, -1);
    }

    public function moveDown(int $subcategoryId): void
    {
        $this->swapWithNeighbour($subcategoryId, 1);
    }

    /**
     * Swap a subcategory's position with the row above/below it *in the list
     * as currently filtered* — one flat sequence across every subcategory,
     * matching Categories\Manage and Services\Manage. Filtering and then
     * reordering within that filter is the normal way to arrange a slice of
     * the list, so the neighbour comes from the filtered set.
     */
    private function swapWithNeighbour(int $subcategoryId, int $offset): void
    {
        if (! auth()->user()->hasPermission('categories.manage')) {
            $this->addError('permission', 'You do not have permission to reorder subcategories.');
            return;
        }

        $this->normalizeSortOrder();

        $ordered = $this->baseQuery()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'sort_order']);

        $index = $ordered->search(fn ($row) => $row->id === $subcategoryId);
        if ($index === false) {
            return;
        }

        $target = $ordered->get($index + $offset);
        if (! $target) {
            return; // already at the top/bottom of the list
        }

        $current = $ordered->get($index);

        DB::transaction(function () use ($current, $target) {
            ServiceSubcategory::whereKey($current->id)->update(['sort_order' => $target->sort_order]);
            ServiceSubcategory::whereKey($target->id)->update(['sort_order' => $current->sort_order]);
        });
    }

    /**
     * sort_order defaults to 0 for every row, and imported rows can share
     * values too — swapping two identical numbers is a no-op, so the arrows
     * would silently do nothing. Collapse to a clean 1..N sequence (globally,
     * not per-filter, so positions stay stable across different filters) the
     * first time an actual move is attempted.
     */
    private function normalizeSortOrder(): void
    {
        $all = ServiceSubcategory::orderBy('sort_order')->orderBy('id')->get(['id', 'sort_order']);

        $needsNormalising = $all->pluck('sort_order')->duplicates()->isNotEmpty()
            || $all->contains(fn ($row) => (int) $row->sort_order === 0);

        if (! $needsNormalising) {
            return;
        }

        DB::transaction(function () use ($all) {
            foreach ($all->values() as $position => $row) {
                ServiceSubcategory::whereKey($row->id)->update(['sort_order' => $position + 1]);
            }
        });
    }

    // ============================= Import/export =============================

    public function exportSubcategories()
    {
        return Excel::download(new SubcategoriesExport, 'subcategories-'.now()->format('Y-m-d').'.xlsx');
    }

    public function downloadTemplate()
    {
        return Excel::download(new SubcategoriesExport(templateOnly: true), 'subcategories-template.xlsx');
    }

    public function toggleImport(): void
    {
        $this->showImport = ! $this->showImport;
        $this->importFile = null;
        $this->importErrors = [];
        $this->importRows = null;
        $this->importMessage = null;
        $this->deactivateMissing = false;
        $this->importRun = null;
    }

    /** See CategoryImporter/CatalogImporter — same real pipeline (mission Phase 14 — Master Catalog Import). */
    public function validateSubcategoriesImport(): void
    {
        $this->importErrors = [];
        $this->importRows = null;
        $this->importMessage = null;
        $this->importRun = null;

        $this->validate(['importFile' => ['required', 'file', 'mimes:xlsx,xls,csv']]);

        $reader = new HeadingRowImport;
        Excel::import($reader, $this->importFile->getRealPath());

        $result = (new SubcategoryImporter)->validateRows($reader->rows);

        if (! empty($result['errors'])) {
            $this->importErrors = $result['errors'];
            return;
        }

        $this->importRows = $result['previewRows'];
    }

    public function commitSubcategoriesImport(): void
    {
        if (empty($this->importRows)) {
            return;
        }

        if (! auth()->user()->hasPermission('categories.manage')) {
            $this->importErrors = [['row' => '-', 'field' => 'permission', 'message' => 'You do not have permission to import subcategories.']];
            return;
        }

        $fileName = $this->importFile?->getClientOriginalName();

        $this->importRun = (new SubcategoryImporter)->commit(
            $this->importRows, auth()->user(), $fileName, $this->deactivateMissing
        );

        if ($this->importRun->status === 'failed') {
            $this->importErrors = [['row' => '-', 'field' => 'commit', 'message' => 'Import failed, nothing was saved.']];
            return;
        }

        $this->importMessage = 'Import complete.';
        $this->importRows = null;
        $this->importFile = null;
    }

    // ============================== Shared helpers ==============================

    private function baseQuery()
    {
        return ServiceSubcategory::query()
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->when($this->filterCategory !== '', fn ($q) => $q->where('category_id', $this->filterCategory))
            ->when($this->filterModule !== '', fn ($q) => $q->whereHas('category', fn ($c) => $c->where('module', $this->filterModule)))
            ->when($this->filterActive !== '', fn ($q) => $q->where('is_active', $this->filterActive === 'active'));
    }

    public function render()
    {
        // One flat running order across all subcategories, same as Categories.
        $subcategories = $this->baseQuery()
            ->with('category')
            ->withCount('services')
            ->orderBy($this->sortField, $this->sortDirection)
            ->orderBy('id')
            ->paginate($this->perPage);

        return view('livewire.subcategories.manage', [
            'subcategories' => $subcategories,
            'categories' => ServiceCategory::orderBy('module')->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'module']),
            'modules' => Modules::options(),
        ])->layout('layouts.admin', ['title' => 'SubCategories']);
    }
}
