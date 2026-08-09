<?php

namespace App\Livewire\Categories;

use App\Exports\CategoriesExport;
use App\Imports\HeadingRowImport;
use App\Models\ServiceCategory;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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

    // --- "Add New" form (one line, pinned at top) ---
    // No sort_order field on purpose: new categories land at the end and
    // ordering is set with the Reorder controls on the list instead, so
    // there's no way for the two to disagree.
    public string $name = '';
    public string $icon = '';
    public string $module = Modules::SERVICE;
    public string $color = '#E5E7EB';
    public bool $isActive = true;

    // --- Edit modal ---
    public bool $showEditModal = false;
    public ?int $editCategoryId = null;
    public string $editName = '';
    public string $editIcon = '';
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
            'icon' => ['required', 'string', 'max:255'],
            'module' => ['required', Rule::in(Modules::slugs())],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [], [
            'icon' => 'icon',
        ]);

        ServiceCategory::create([
            'module' => $this->module,
            'name' => $this->name,
            'slug' => Str::slug($this->name).'-'.Str::random(4),
            'icon' => $this->icon,
            'color' => $this->color ?: null,
            // New rows go to the end of the list; Reorder moves them from there.
            'sort_order' => (int) ServiceCategory::max('sort_order') + 1,
            'is_active' => $this->isActive,
        ]);

        $this->reset(['name', 'icon', 'color', 'isActive']);
        $this->color = '#E5E7EB';
        $this->flashMessage = 'Category created.';
    }

    // ============================== Edit modal ==============================

    public function edit(int $categoryId): void
    {
        $category = ServiceCategory::findOrFail($categoryId);

        $this->editCategoryId = $category->id;
        $this->editName = $category->name;
        $this->editIcon = $category->icon ?? '';
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
            'editIcon' => ['required', 'string', 'max:255'],
            'editModule' => ['required', Rule::in(Modules::slugs())],
            'editColor' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $category = ServiceCategory::findOrFail($this->editCategoryId);
        $category->update([
            'module' => $this->editModule,
            'name' => $this->editName,
            'icon' => $this->editIcon,
            'color' => $this->editColor ?: null,
            'is_active' => $this->editIsActive,
        ]);

        $this->showEditModal = false;
        $this->flashMessage = 'Category updated.';
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->resetValidation();
    }

    // Inline tick/cross toggle — no page reload.
    public function toggleCategory(int $categoryId): void
    {
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

        ServiceCategory::findOrFail($this->confirmingDeleteId)->delete();

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
    }

    public function validateCategoriesImport(): void
    {
        $this->categoriesImportErrors = [];
        $this->categoriesImportRows = null;
        $this->categoriesImportMessage = null;

        $this->validate(['categoriesImportFile' => ['required', 'file', 'mimes:xlsx,xls,csv']]);

        $reader = new HeadingRowImport;
        Excel::import($reader, $this->categoriesImportFile->getRealPath());

        $errors = [];
        $validRows = [];

        foreach ($reader->rows as $i => $row) {
            $rowNum = $i + 2; // +1 for zero-index, +1 for the header row itself
            $validator = Validator::make($row->toArray(), [
                'name' => ['required', 'string', 'max:255'],
                'module' => ['nullable', Rule::in(Modules::slugs())],
                'is_active' => ['nullable'],
                'image' => ['nullable', 'string', 'max:2048'],
                'sort_order' => ['nullable', 'integer'],
            ]);

            if ($validator->fails()) {
                foreach ($validator->errors()->messages() as $field => $messages) {
                    $errors[] = ['row' => $rowNum, 'field' => $field, 'message' => $messages[0]];
                }
                continue;
            }

            $validRows[] = [
                'id' => $this->blankToNull($row['id'] ?? null),
                'module' => $this->blankToNull($row['module'] ?? null) ?? Modules::SERVICE,
                'name' => $row['name'],
                'is_active' => $this->normalizeBool($row['is_active'] ?? 1),
                'image' => $this->blankToNull($row['image'] ?? null),
                'icon' => $this->blankToNull($row['icon'] ?? null),
                'color' => $this->blankToNull($row['color'] ?? null),
                'description' => $this->blankToNull($row['description'] ?? null),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ];
        }

        if (! empty($errors)) {
            $this->categoriesImportErrors = $errors;
            return;
        }

        if (empty($validRows)) {
            $this->categoriesImportErrors = [['row' => '-', 'field' => 'file', 'message' => 'No data rows found in this file.']];
            return;
        }

        $this->categoriesImportRows = $validRows;
    }

    public function commitCategoriesImport(): void
    {
        if (empty($this->categoriesImportRows)) {
            return;
        }

        try {
            DB::transaction(function () {
                foreach ($this->categoriesImportRows as $row) {
                    $id = $row['id'];
                    unset($row['id']);

                    if ($id) {
                        // Preserve the source file's id (matters for Glover files —
                        // subcategories.xlsx references these same category ids, so
                        // keeping them lets all three sheets import as one linked set).
                        ServiceCategory::updateOrCreate(['id' => $id], $row);
                    } else {
                        $row['slug'] = Str::slug($row['name']).'-'.Str::random(4);
                        ServiceCategory::create($row);
                    }
                }
            });
        } catch (\Throwable $e) {
            $this->categoriesImportErrors = [['row' => '-', 'field' => 'commit', 'message' => 'Import failed, nothing was saved: '.$e->getMessage()]];
            return;
        }

        $count = count($this->categoriesImportRows);
        $this->categoriesImportMessage = "Imported {$count} ".Str::plural('category', $count).' successfully.';
        $this->categoriesImportRows = null;
        $this->categoriesImportFile = null;
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
