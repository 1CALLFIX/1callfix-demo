<?php

namespace App\Livewire\Services;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use Illuminate\Support\Str;
use Livewire\Component;

class Form extends Component
{
    public ?int $serviceId = null;

    public string $categoryId = '';
    public string $subcategoryId = '';
    public string $name = '';
    public string $description = '';
    public string $basePrice = '';
    public string $discountPrice = '';
    public string $priceType = 'fixed';
    public string $durationEstimateMins = '60';
    public string $coverImage = '';
    public bool $isActive = true;

    public string $flashMessage = '';

    public function mount(?int $serviceId = null): void
    {
        if ($serviceId) {
            $service = Service::findOrFail($serviceId);

            $this->serviceId = $service->id;
            $this->categoryId = (string) $service->category_id;
            $this->subcategoryId = $service->subcategory_id ? (string) $service->subcategory_id : '';
            $this->name = $service->name;
            $this->description = $service->description ?? '';
            $this->basePrice = (string) $service->base_price;
            $this->discountPrice = $service->discount_price !== null ? (string) $service->discount_price : '';
            $this->priceType = $service->price_type;
            $this->durationEstimateMins = (string) $service->duration_estimate_mins;
            $this->coverImage = $service->cover_image ?? '';
            $this->isActive = $service->is_active;
        }
    }

    // Clear the chosen subcategory whenever the category changes, since a
    // subcategory from the old category would no longer make sense.
    public function updatedCategoryId(): void
    {
        $this->subcategoryId = '';
    }

    public function getSubcategoriesProperty()
    {
        if (! $this->categoryId) {
            return collect();
        }

        return ServiceSubcategory::where('category_id', $this->categoryId)
            ->orderBy('name')
            ->get();
    }

    public function save(): void
    {
        $rules = [
            'categoryId' => ['required', 'exists:service_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'basePrice' => ['required', 'numeric', 'min:0'],
            'priceType' => ['required', 'in:fixed,hourly,quote_on_inspection'],
            'durationEstimateMins' => ['required', 'integer', 'min:1'],
        ];

        // discountPrice is a Livewire string property defaulting to '', not
        // null — 'nullable' only exempts an actual null, so leaving this
        // field blank (the normal case) would otherwise fail the 'numeric'
        // rule against ''. Only validate it at all when something was typed.
        if ($this->discountPrice !== '') {
            $rules['discountPrice'] = ['numeric', 'min:0', 'lt:basePrice'];
        }

        $this->validate($rules);

        $data = [
            'category_id' => $this->categoryId,
            'subcategory_id' => $this->subcategoryId ?: null,
            'name' => $this->name,
            'description' => $this->description ?: null,
            'base_price' => $this->basePrice,
            'discount_price' => $this->discountPrice !== '' ? $this->discountPrice : null,
            'price_type' => $this->priceType,
            'duration_estimate_mins' => $this->durationEstimateMins,
            'cover_image' => $this->coverImage ?: null,
            'is_active' => $this->isActive,
        ];

        if ($this->serviceId) {
            $service = Service::findOrFail($this->serviceId);
            $service->update($data);
        } else {
            // services.slug has no uniqueness constraint in the schema, unlike
            // franchises/categories — a plain slug is enough, no random suffix.
            $data['slug'] = Str::slug($this->name);
            $service = Service::create($data);
            $this->serviceId = $service->id;
        }

        $this->flashMessage = 'Saved successfully.';
    }

    public function render()
    {
        return view('livewire.services.form', [
            'categories' => ServiceCategory::orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.admin', ['title' => $this->serviceId ? 'Edit Service' : 'New Service']);
    }
}
