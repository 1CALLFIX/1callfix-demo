<?php

namespace App\Livewire\Services;

use App\Models\Service;
use App\Models\ServiceCategory;
use Livewire\Component;

class Index extends Component
{
    public string $categoryId = '';
    public string $subcategoryId = '';

    public function mount(): void
    {
        // Filters coming from Categories\Index's "View Services" link
        // (?subcategoryId=..) — same query-string caveat as Subcategories\Form:
        // this route has no {categoryId}/{subcategoryId} segments, so both are
        // read directly rather than declared as typed mount() parameters
        // (which would silently never get auto-injected).
        if ($id = request()->query('categoryId')) {
            $this->categoryId = (string) $id;
        }
        if ($id = request()->query('subcategoryId')) {
            $this->subcategoryId = (string) $id;
        }
    }

    public function render()
    {
        $services = Service::with(['category', 'subcategory'])
            ->when($this->categoryId, fn ($q) => $q->where('category_id', $this->categoryId))
            ->when($this->subcategoryId, fn ($q) => $q->where('subcategory_id', $this->subcategoryId))
            ->latest()
            ->paginate(20);

        $categories = ServiceCategory::orderBy('name')->get(['id', 'name']);

        return view('livewire.services.index', compact('services', 'categories'))
            ->layout('layouts.admin', ['title' => 'Services']);
    }
}
