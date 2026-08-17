<?php

namespace App\Livewire\MarketplaceCategories;

use App\Models\MarketplaceCategory;
use App\Support\Modules;
use Livewire\Component;
use Livewire\WithPagination;

/** Phase 24 (Marketplace Foundation) admin screen. Global taxonomy (no franchise/zone scope -- same shape as ServiceCategory/PropertyType, categories aren't franchise-owned). */
class Manage extends Component
{
    use WithPagination;

    public string $module = 'commerce';
    public string $search = '';

    public string $name = '';
    public ?int $parentId = null;

    public bool $showEditModal = false;
    public ?int $editCategoryId = null;
    public string $editName = '';
    public bool $editIsActive = true;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('marketplace_categories.manage'), 403, 'You do not have permission to manage marketplace categories.');
    }

    public function createCategory(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'module' => 'required|in:'.implode(',', array_keys(Modules::ALL)),
            'parentId' => 'nullable|exists:marketplace_categories,id',
        ]);

        MarketplaceCategory::create([
            'name' => $this->name, 'module' => $this->module, 'parent_id' => $this->parentId, 'is_active' => true,
        ]);

        $this->reset(['name', 'parentId']);
        session()->flash('message', 'Category created.');
    }

    public function editCategory(int $categoryId): void
    {
        $category = MarketplaceCategory::findOrFail($categoryId);

        $this->editCategoryId = $category->id;
        $this->editName = $category->name;
        $this->editIsActive = $category->is_active;
        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
    }

    public function saveEdit(): void
    {
        $category = MarketplaceCategory::findOrFail($this->editCategoryId);

        $this->validate(['editName' => 'required|string|max:255']);

        $category->update(['name' => $this->editName, 'is_active' => $this->editIsActive]);

        $this->showEditModal = false;
        session()->flash('message', 'Category updated.');
    }

    public function render()
    {
        $categories = MarketplaceCategory::query()
            ->where('module', $this->module)
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->with('parent')
            ->orderBy('position')->orderBy('name')
            ->paginate(20);

        return view('livewire.marketplace-categories.manage', [
            'categories' => $categories,
            'parentOptions' => MarketplaceCategory::where('module', $this->module)->whereNull('parent_id')->orderBy('name')->get(),
            'modules' => Modules::ALL,
        ])->layout('layouts.admin', ['title' => 'Marketplace Categories']);
    }
}
