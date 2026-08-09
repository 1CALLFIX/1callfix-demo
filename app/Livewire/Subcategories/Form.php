<?php

namespace App\Livewire\Subcategories;

use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use Illuminate\Support\Str;
use Livewire\Component;

class Form extends Component
{
    public ?int $subcategoryId = null;

    public string $categoryId = '';
    public string $name = '';
    public string $icon = '';
    public string $description = '';
    public string $sortOrder = '0';
    public bool $isActive = true;

    public string $flashMessage = '';

    public function mount(?int $subcategoryId = null): void
    {
        if ($subcategoryId) {
            $sub = ServiceSubcategory::findOrFail($subcategoryId);
            $this->subcategoryId = $sub->id;
            $this->categoryId = (string) $sub->category_id;
            $this->name = $sub->name;
            $this->icon = $sub->icon ?? '';
            $this->description = $sub->description ?? '';
            $this->sortOrder = (string) $sub->sort_order;
            $this->isActive = $sub->is_active;
            return;
        }

        // Pre-fill from the "+ Subcategory" link on a specific category row
        // (Categories\Index). Read directly off the query string rather than
        // a typed mount() parameter — Livewire only auto-injects parameters
        // that are actual {route} segments, and this route has none; a
        // ?categoryId=5 query string is never bound to a same-named mount()
        // arg automatically.
        if ($id = request()->query('categoryId')) {
            $this->categoryId = (string) $id;
        }
    }

    public function save(): void
    {
        $this->validate([
            'categoryId' => ['required', 'exists:service_categories,id'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $data = [
            'category_id' => $this->categoryId,
            'name' => $this->name,
            'icon' => $this->icon ?: null,
            'description' => $this->description ?: null,
            'sort_order' => (int) $this->sortOrder,
            'is_active' => $this->isActive,
        ];

        if ($this->subcategoryId) {
            $sub = ServiceSubcategory::findOrFail($this->subcategoryId);
            $sub->update($data);
        } else {
            $data['slug'] = Str::slug($this->name).'-'.Str::random(4);
            $sub = ServiceSubcategory::create($data);
            $this->subcategoryId = $sub->id;
        }

        $this->flashMessage = 'Saved successfully.';
    }

    public function render()
    {
        return view('livewire.subcategories.form', [
            'categories' => ServiceCategory::orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.admin', ['title' => $this->subcategoryId ? 'Edit Subcategory' : 'New Subcategory']);
    }
}
