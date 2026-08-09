<?php

namespace App\Livewire\Categories;

use App\Models\ServiceCategory;
use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        $categories = ServiceCategory::with(['subcategories' => fn ($q) => $q->withCount('services')->orderBy('sort_order')])
            ->withCount('services')
            ->orderBy('sort_order')
            ->get();

        return view('livewire.categories.index', compact('categories'))
            ->layout('layouts.admin', ['title' => 'Categories']);
    }
}
