<?php

namespace App\Livewire\Subcategories;

use App\Exports\SubcategoriesExport;
use App\Imports\HeadingRowImport;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
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
// The one real difference from Categories: a subcategory's ordering only
// means anything *within its own parent category* (that's how the app renders
// them), so sort_order is normalised and swapped per-category rather than
// globally. Module isn't stored here at all — it's inherited from the parent
// category, shown read-only, and filterable via that relation.
class Manage extends Component
{
    use WithFileUploads;
    use WithPagination;

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

        ServiceSubcategory::create([
            'category_id' => $this->categoryId,
            'name' => $this->name,
            'slug' => Str::slug($this->name).'-'.Str::random(4),
            'image' => $this->storeIcon($this->iconFile),
            // Lands at the end of its own category's list; Reorder moves it.
            'sort_order' => (int) ServiceSubcategory::where('category_id', $this->categoryId)->max('sort_order') + 1,
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

        $sub = ServiceSubcategory::findOrFail($this->editSubcategoryId);

        $image = $sub->image;
        if ($this->editIconFile) {
            $image = $this->storeIcon($this->editIconFile);
            $this->deleteStoredIcon($sub->image);
        }

        // Moving to a different category makes the old position meaningless —
        // send it to the end of the new category rather than keeping a number
        // that collides with whatever already sits there.
        $sortOrder = $sub->sort_order;
        if ((int) $this->editCategoryId !== (int) $sub->category_id) {
            $sortOrder = (int) ServiceSubcategory::where('category_id', $this->editCategoryId)->max('sort_order') + 1;
        }

        $sub->update([
            'category_id' => $this->editCategoryId,
            'name' => $this->editName,
            'image' => $image,
            'sort_order' => $sortOrder,
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
     * Swap with the neighbouring row *inside the same parent category* — a
     * subcategory's order only affects where it sits within that category in
     * the app, so moving it past a different category's rows would be
     * meaningless. At the top/bottom of its own category the arrows no-op.
     */
    private function swapWithNeighbour(int $subcategoryId, int $offset): void
    {
        $sub = ServiceSubcategory::findOrFail($subcategoryId);

        $this->normalizeSortOrder($sub->category_id);

        $ordered = ServiceSubcategory::where('category_id', $sub->category_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'sort_order']);

        $index = $ordered->search(fn ($row) => $row->id === $subcategoryId);
        if ($index === false) {
            return;
        }

        $target = $ordered->get($index + $offset);
        if (! $target) {
            return;
        }

        $current = $ordered->get($index);

        DB::transaction(function () use ($current, $target) {
            ServiceSubcategory::whereKey($current->id)->update(['sort_order' => $target->sort_order]);
            ServiceSubcategory::whereKey($target->id)->update(['sort_order' => $current->sort_order]);
        });
    }

    /**
     * Collapse one category's subcategories to a clean 1..N run. Imported and
     * legacy rows all share sort_order 0, and swapping two identical numbers
     * is a no-op that would make the arrows look broken.
     */
    private function normalizeSortOrder(int $categoryId): void
    {
        $rows = ServiceSubcategory::where('category_id', $categoryId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'sort_order']);

        $needsNormalising = $rows->pluck('sort_order')->duplicates()->isNotEmpty()
            || $rows->contains(fn ($row) => (int) $row->sort_order === 0);

        if (! $needsNormalising) {
            return;
        }

        DB::transaction(function () use ($rows) {
            foreach ($rows->values() as $position => $row) {
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
    }

    public function validateSubcategoriesImport(): void
    {
        $this->importErrors = [];
        $this->importRows = null;
        $this->importMessage = null;

        $this->validate(['importFile' => ['required', 'file', 'mimes:xlsx,xls,csv']]);

        $reader = new HeadingRowImport;
        Excel::import($reader, $this->importFile->getRealPath());

        $errors = [];
        $validRows = [];

        foreach ($reader->rows as $i => $row) {
            $rowNum = $i + 2;
            $validator = Validator::make($row->toArray(), [
                'name' => ['required', 'string', 'max:255'],
                'category_id' => ['required', 'integer', 'exists:service_categories,id'],
                'is_active' => ['nullable'],
                'image' => ['nullable', 'string', 'max:2048'],
                'sort_order' => ['nullable', 'integer'],
            ], [
                'category_id.exists' => 'category_id :input does not exist — import categories first, or check the id.',
            ]);

            if ($validator->fails()) {
                foreach ($validator->errors()->messages() as $field => $messages) {
                    $errors[] = ['row' => $rowNum, 'field' => $field, 'message' => $messages[0]];
                }
                continue;
            }

            $validRows[] = [
                'id' => $this->blankToNull($row['id'] ?? null),
                'category_id' => $row['category_id'],
                'name' => $row['name'],
                'is_active' => $this->normalizeBool($row['is_active'] ?? 1),
                'image' => $this->blankToNull($row['image'] ?? null),
                'icon' => $this->blankToNull($row['icon'] ?? null),
                'description' => $this->blankToNull($row['description'] ?? null),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ];
        }

        if (! empty($errors)) {
            $this->importErrors = $errors;
            return;
        }

        if (empty($validRows)) {
            $this->importErrors = [['row' => '-', 'field' => 'file', 'message' => 'No data rows found in this file.']];
            return;
        }

        $this->importRows = $validRows;
    }

    public function commitSubcategoriesImport(): void
    {
        if (empty($this->importRows)) {
            return;
        }

        try {
            DB::transaction(function () {
                foreach ($this->importRows as $row) {
                    $id = $row['id'];
                    unset($row['id']);

                    if ($id) {
                        ServiceSubcategory::updateOrCreate(['id' => $id], $row);
                    } else {
                        $row['slug'] = Str::slug($row['name']).'-'.Str::random(4);
                        ServiceSubcategory::create($row);
                    }
                }
            });
        } catch (\Throwable $e) {
            $this->importErrors = [['row' => '-', 'field' => 'commit', 'message' => 'Import failed, nothing was saved: '.$e->getMessage()]];
            return;
        }

        $count = count($this->importRows);
        $this->importMessage = "Imported {$count} ".Str::plural('subcategory', $count).' successfully.';
        $this->importRows = null;
        $this->importFile = null;
    }

    // ============================== Shared helpers ==============================

    private function blankToNull($value)
    {
        $value = is_string($value) ? trim($value) : $value;
        return ($value === '' || $value === null) ? null : $value;
    }

    private function normalizeBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $value = strtolower(trim((string) $value));
        return ! in_array($value, ['0', 'false', 'no', 'inactive', ''], true);
    }

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
        $query = $this->baseQuery()->with('category')->withCount('services');

        // Default view mirrors how the app renders them: grouped by their
        // parent category's own order, then by position within it.
        if ($this->sortField === 'sort_order') {
            $query->orderBy(
                ServiceCategory::select('sort_order')->whereColumn('service_categories.id', 'service_subcategories.category_id'),
                $this->sortDirection
            )->orderBy('category_id')->orderBy('sort_order', $this->sortDirection);
        } else {
            $query->orderBy($this->sortField, $this->sortDirection);
        }

        $subcategories = $query->orderBy('id')->paginate($this->perPage);

        return view('livewire.subcategories.manage', [
            'subcategories' => $subcategories,
            'categories' => ServiceCategory::orderBy('module')->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'module']),
            'modules' => Modules::options(),
        ])->layout('layouts.admin', ['title' => 'SubCategories']);
    }
}
