<?php

namespace App\Livewire\Categories;

use App\Models\ServiceCategory;
use Illuminate\Support\Str;
use Livewire\Component;

class Form extends Component
{
    public ?int $categoryId = null;

    public string $name = '';
    public string $icon = '';
    public string $description = '';
    public string $sortOrder = '0';
    public bool $isActive = true;

    public string $flashMessage = '';

    public function mount(?int $categoryId = null): void
    {
        if ($categoryId) {
            $category = ServiceCategory::findOrFail($categoryId);
            $this->categoryId = $category->id;
            $this->name = $category->name;
            $this->icon = $category->icon ?? '';
            $this->description = $category->description ?? '';
            $this->sortOrder = (string) $category->sort_order;
            $this->isActive = $category->is_active;
        }
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $data = [
            'name' => $this->name,
            'icon' => $this->icon ?: null,
            'description' => $this->description ?: null,
            'sort_order' => (int) $this->sortOrder,
            'is_active' => $this->isActive,
        ];

        if ($this->categoryId) {
            $category = ServiceCategory::findOrFail($this->categoryId);
            $category->update($data);
        } else {
            $data['slug'] = Str::slug($this->name).'-'.Str::random(4);
            $category = ServiceCategory::create($data);
            $this->categoryId = $category->id;
        }

        $this->flashMessage = 'Saved successfully.';
    }

    public function render()
    {
        return view('livewire.categories.form')
            ->layout('layouts.admin', ['title' => $this->categoryId ? 'Edit Category' : 'New Category']);
    }
}
