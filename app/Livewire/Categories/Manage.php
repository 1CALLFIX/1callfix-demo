<?php

namespace App\Livewire\Categories;

use App\Exports\CategoriesExport;
use App\Imports\HeadingRowImport;
use App\Models\ServiceCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;

// Replaces the old Categories\Index + Categories\Form pair: one screen, an
// inline "Add New" form pinned at the top, the list directly below it
// (updates live, no redirect), and edit via a modal instead of a separate
// route. See PROJECT_HANDOFF.md-adjacent chat for the restructure spec —
// Subcategories/Services get the same treatment in their own follow-up
// components, not here.
class Manage extends Component
{
    use WithFileUploads;

    // --- "Add New" form (pinned at top of page) ---
    public string $name = '';
    public string $icon = '';
    public string $image = '';
    public string $description = '';
    public string $sortOrder = '0';
    public bool $isActive = true;

    // --- Edit modal ---
    public bool $showEditModal = false;
    public ?int $editCategoryId = null;
    public string $editName = '';
    public string $editIcon = '';
    public string $editImage = '';
    public string $editDescription = '';
    public string $editSortOrder = '0';
    public bool $editIsActive = true;

    public string $flashMessage = '';

    // --- Import/export (unchanged behavior, just carried over from Index) ---
    public $categoriesImportFile = null;
    public bool $showCategoriesImport = false;
    public array $categoriesImportErrors = [];
    public ?array $categoriesImportRows = null;
    public ?string $categoriesImportMessage = null;

    // ============================= Add New =============================

    public function save(): void
    {
        $rules = ['name' => ['required', 'string', 'max:255']];

        // Same '' vs null caveat as before: this is a Livewire string
        // property defaulting to '', not null, so 'nullable' alone
        // wouldn't exempt a blank field from the 'url' rule.
        if ($this->image !== '') {
            $rules['image'] = ['url', 'max:2048'];
        }

        $this->validate($rules);

        ServiceCategory::create([
            'name' => $this->name,
            'slug' => Str::slug($this->name).'-'.Str::random(4),
            'icon' => $this->icon ?: null,
            'image' => $this->image ?: null,
            'description' => $this->description ?: null,
            'sort_order' => (int) $this->sortOrder,
            'is_active' => $this->isActive,
        ]);

        $this->reset(['name', 'icon', 'image', 'description', 'sortOrder', 'isActive']);
        $this->flashMessage = 'Category created.';
    }

    // ============================== Edit modal ==============================

    public function edit(int $categoryId): void
    {
        $category = ServiceCategory::findOrFail($categoryId);

        $this->editCategoryId = $category->id;
        $this->editName = $category->name;
        $this->editIcon = $category->icon ?? '';
        $this->editImage = $category->image ?? '';
        $this->editDescription = $category->description ?? '';
        $this->editSortOrder = (string) $category->sort_order;
        $this->editIsActive = $category->is_active;

        $this->resetValidation();
        $this->showEditModal = true;
    }

    public function update(): void
    {
        $rules = ['editName' => ['required', 'string', 'max:255']];

        if ($this->editImage !== '') {
            $rules['editImage'] = ['url', 'max:2048'];
        }

        $this->validate($rules);

        $category = ServiceCategory::findOrFail($this->editCategoryId);
        $category->update([
            'name' => $this->editName,
            'icon' => $this->editIcon ?: null,
            'image' => $this->editImage ?: null,
            'description' => $this->editDescription ?: null,
            'sort_order' => (int) $this->editSortOrder,
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

    // Inline toggle — no page reload. Deliberately not routed through the
    // edit modal since this is a single boolean flip, not a form save.
    public function toggleCategory(int $categoryId): void
    {
        $category = ServiceCategory::findOrFail($categoryId);
        $category->update(['is_active' => ! $category->is_active]);
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
                'name' => $row['name'],
                'is_active' => $this->normalizeBool($row['is_active'] ?? 1),
                'image' => $this->blankToNull($row['image'] ?? null),
                'icon' => $this->blankToNull($row['icon'] ?? null),
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

    public function render()
    {
        $categories = ServiceCategory::withCount(['subcategories', 'services'])
            ->orderBy('sort_order')
            ->get();

        return view('livewire.categories.manage', compact('categories'))
            ->layout('layouts.admin', ['title' => 'Categories']);
    }
}
