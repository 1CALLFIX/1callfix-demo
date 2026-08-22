<?php

namespace App\Livewire\Services;

use App\Exports\ServicesExport;
use App\Imports\HeadingRowImport;
use App\Models\CatalogImportRun;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use App\Models\Setting;
use App\Services\Catalog\ServiceImporter;
use App\Support\Concerns\HasCsvExport;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;

// Third and last of the catalog screens to move to the one-component pattern
// (see Categories\Manage). Replaces Services\Index + Services\Form.
//
// Validation and the category -> subcategory dependent-dropdown behaviour are
// carried over from Services\Form unchanged — this is a layout/interaction
// restructure, not a rules change. The two additions are the ones applied to
// all three screens: an uploaded cover image (rather than pasting a URL) and
// a sort_order used by Reorder.
//
// Services have far more fields than a category, so the pinned "Add New" form
// is a compact grid rather than a literal single line, and `description` (the
// one field needing a rich-text editor) lives in the edit modal only — quick
// add covers the priced essentials, the modal covers the full record.
class Manage extends Component
{
    use WithFileUploads;
    use WithPagination;
    use HasCsvExport;

    // --- "Add New" form ---
    public string $categoryId = '';
    public string $subcategoryId = '';
    public string $name = '';
    public string $description = '';
    public string $basePrice = '';
    public string $discountPrice = '';
    public string $priceType = 'fixed';
    public string $durationEstimateMins = '60';
    public $coverImageFile = null;
    public bool $isActive = true;
    public bool $locationRequired = true;
    public bool $ageRestriction = false;
    /** Bumped after each save so the add form's Trix editor is rebuilt empty. */
    public int $addFormKey = 0;

    // --- Edit modal ---
    public bool $showEditModal = false;
    public ?int $editServiceId = null;
    public string $editCategoryId = '';
    public string $editSubcategoryId = '';
    public string $editName = '';
    public string $editDescription = '';
    public string $editBasePrice = '';
    public string $editDiscountPrice = '';
    public string $editPriceType = 'fixed';
    public string $editDurationEstimateMins = '60';
    public $editCoverImageFile = null;
    public ?string $editExistingImage = null;
    public bool $editIsActive = true;
    public bool $editLocationRequired = true;
    public bool $editAgeRestriction = false;

    // --- View details modal (read-only, the eye icon) ---
    public bool $showViewModal = false;
    public ?int $viewServiceId = null;

    // --- Delete confirmation ---
    public ?int $confirmingDeleteId = null;
    public string $deleteBlockedReason = '';
    public string $deleteWarning = '';

    // --- List controls ---
    public string $search = '';
    public string $filterModule = '';
    public string $filterCategory = '';
    public string $filterSubcategory = '';
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

    public function mount(): void
    {
        // No view-level check existed at all — only write actions checked
        // services.manage (Phase 11 audit finding, same bug class
        // addendum #1 already fixed on 15 other screens).
        abort_unless(auth()->user()->hasPermissionAnywhere('services.manage'), 403, 'You do not have permission to view services.');

        // Deep links like /admin/services?categoryId=5 still work. Read off the
        // query string rather than typed mount() parameters: this route has no
        // {categoryId} segment, and Livewire only auto-injects actual route
        // segments — a same-named mount() arg would silently never be filled.
        if ($id = request()->query('categoryId')) {
            $this->filterCategory = (string) $id;
            $this->showFilters = true;
        }
        if ($id = request()->query('subcategoryId')) {
            $this->filterSubcategory = (string) $id;
            $this->showFilters = true;
        }
    }

    // ===================== Dependent dropdowns (unchanged rules) =====================

    /** Clear the chosen subcategory when the category changes — it no longer applies. */
    public function updatedCategoryId(): void
    {
        $this->subcategoryId = '';
    }

    public function updatedEditCategoryId(): void
    {
        $this->editSubcategoryId = '';
    }

    public function getSubcategoriesProperty()
    {
        if (! $this->categoryId) {
            return collect();
        }

        return ServiceSubcategory::where('category_id', $this->categoryId)->orderBy('sort_order')->orderBy('name')->get();
    }

    public function getEditSubcategoriesProperty()
    {
        if (! $this->editCategoryId) {
            return collect();
        }

        return ServiceSubcategory::where('category_id', $this->editCategoryId)->orderBy('sort_order')->orderBy('name')->get();
    }

    /** Subcategory options for the *filter* bar, following the category filter. */
    public function getFilterSubcategoriesProperty()
    {
        if (! $this->filterCategory) {
            return collect();
        }

        return ServiceSubcategory::where('category_id', $this->filterCategory)->orderBy('name')->get();
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedFilterModule(): void { $this->resetPage(); }
    public function updatedFilterActive(): void { $this->resetPage(); }
    public function updatedFilterSubcategory(): void { $this->resetPage(); }

    public function updatedFilterCategory(): void
    {
        $this->filterSubcategory = '';
        $this->resetPage();
    }

    public function updatedPerPage(): void { $this->resetPage(); }

    // ============================= Add New =============================

    public function save(): void
    {
        // Rules lifted verbatim from the old Services\Form::save().
        $rules = [
            'categoryId' => ['required', 'exists:service_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'basePrice' => ['required', 'numeric', 'min:0'],
            'priceType' => ['required', 'in:fixed,hourly,quote_on_inspection'],
            'durationEstimateMins' => ['required', 'integer', 'min:1'],
            'coverImageFile' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
        ];

        // discountPrice is a Livewire string property defaulting to '', not
        // null — 'nullable' only exempts an actual null, so leaving this field
        // blank (the normal case) would otherwise fail the 'numeric' rule
        // against ''. Only validate it at all when something was typed.
        if ($this->discountPrice !== '') {
            $rules['discountPrice'] = ['numeric', 'min:0', 'lt:basePrice'];
        }

        $this->validate($rules, [
            'coverImageFile.max' => 'The cover image must be 2 MB or smaller.',
        ], [
            'categoryId' => 'category',
            'basePrice' => 'base price',
            'discountPrice' => 'discount price',
            'priceType' => 'price type',
            'durationEstimateMins' => 'duration',
            'coverImageFile' => 'cover image',
        ]);

        // services carries no franchise column — it's shared catalog data,
        // same global-only treatment as Categories\Manage.
        if (! auth()->user()->hasPermission('services.manage')) {
            $this->addError('permission', 'You do not have permission to manage services.');
            return;
        }

        Service::create([
            'category_id' => $this->categoryId,
            'subcategory_id' => $this->subcategoryId ?: null,
            'name' => $this->name,
            // services.slug has no uniqueness constraint in the schema, unlike
            // franchises/categories — a plain slug is enough, no random suffix.
            'slug' => Str::slug($this->name),
            'description' => $this->description ?: null,
            'base_price' => $this->basePrice,
            'discount_price' => $this->discountPrice !== '' ? $this->discountPrice : null,
            'price_type' => $this->priceType,
            'duration_estimate_mins' => $this->durationEstimateMins,
            'cover_image' => $this->coverImageFile ? $this->storeCover($this->coverImageFile) : null,
            'is_active' => $this->isActive,
            'location_required' => $this->locationRequired,
            'age_restriction' => $this->ageRestriction,
            // New rows go to the end of the list; Reorder moves them from there.
            'sort_order' => (int) Service::max('sort_order') + 1,
        ]);

        $this->reset(['name', 'description', 'basePrice', 'discountPrice', 'coverImageFile', 'isActive', 'ageRestriction']);
        $this->priceType = 'fixed';
        $this->durationEstimateMins = '60';
        $this->locationRequired = true;
        // Trix holds its own DOM state, so clearing the property isn't enough
        // to blank the visible editor — the add form's editor is keyed on this
        // counter so it gets rebuilt empty after each successful save.
        $this->addFormKey++;
        $this->flashMessage = 'Service created.';
    }

    private function storeCover($file): string
    {
        return $file->store('services', 'public');
    }

    private function deleteStoredCover(?string $image): void
    {
        if (! $image || Str::startsWith($image, ['http://', 'https://', '//', 'data:'])) {
            return;
        }

        Storage::disk('public')->delete($image);
    }

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

    // ============================== Edit modal ==============================

    public function edit(int $serviceId): void
    {
        $service = Service::findOrFail($serviceId);

        $this->editServiceId = $service->id;
        $this->editCategoryId = (string) $service->category_id;
        $this->editSubcategoryId = $service->subcategory_id ? (string) $service->subcategory_id : '';
        $this->editName = $service->name;
        $this->editDescription = $service->description ?? '';
        $this->editBasePrice = (string) $service->base_price;
        $this->editDiscountPrice = $service->discount_price !== null ? (string) $service->discount_price : '';
        $this->editPriceType = $service->price_type;
        $this->editDurationEstimateMins = (string) $service->duration_estimate_mins;
        $this->editCoverImageFile = null;
        $this->editExistingImage = $service->cover_image;
        $this->editIsActive = $service->is_active;
        $this->editLocationRequired = $service->location_required;
        $this->editAgeRestriction = $service->age_restriction;

        $this->resetValidation();
        // Edit is also reachable from the details modal's footer — make sure
        // the two never stack on top of each other.
        $this->showViewModal = false;
        $this->showEditModal = true;
    }

    // ========================= View details (read-only) =========================

    public function view(int $serviceId): void
    {
        $this->viewServiceId = $serviceId;
        $this->showViewModal = true;
    }

    public function closeViewModal(): void
    {
        $this->showViewModal = false;
        $this->viewServiceId = null;
    }

    /** The row behind the details modal, loaded fresh so it reflects edits. */
    public function getViewingServiceProperty(): ?Service
    {
        if (! $this->viewServiceId) {
            return null;
        }

        return Service::with(['category', 'subcategory'])->find($this->viewServiceId);
    }

    public function update(): void
    {
        $rules = [
            'editCategoryId' => ['required', 'exists:service_categories,id'],
            'editName' => ['required', 'string', 'max:255'],
            'editBasePrice' => ['required', 'numeric', 'min:0'],
            'editPriceType' => ['required', 'in:fixed,hourly,quote_on_inspection'],
            'editDurationEstimateMins' => ['required', 'integer', 'min:1'],
            'editCoverImageFile' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
        ];

        if ($this->editDiscountPrice !== '') {
            $rules['editDiscountPrice'] = ['numeric', 'min:0', 'lt:editBasePrice'];
        }

        $this->validate($rules, [
            'editCoverImageFile.max' => 'The cover image must be 2 MB or smaller.',
        ], [
            'editCategoryId' => 'category',
            'editBasePrice' => 'base price',
            'editDiscountPrice' => 'discount price',
            'editPriceType' => 'price type',
            'editDurationEstimateMins' => 'duration',
            'editCoverImageFile' => 'cover image',
        ]);

        if (! auth()->user()->hasPermission('services.manage')) {
            $this->addError('permission', 'You do not have permission to manage services.');
            return;
        }

        $service = Service::findOrFail($this->editServiceId);

        $cover = $service->cover_image;
        if ($this->editCoverImageFile) {
            $cover = $this->storeCover($this->editCoverImageFile);
            $this->deleteStoredCover($service->cover_image);
        }

        // sort_order is one flat sequence across every service, so moving a
        // service to a different category leaves its position untouched.
        $newSubcategoryId = $this->editSubcategoryId ?: null;

        $service->update([
            'category_id' => $this->editCategoryId,
            'subcategory_id' => $newSubcategoryId,
            'name' => $this->editName,
            'description' => $this->editDescription ?: null,
            'base_price' => $this->editBasePrice,
            'discount_price' => $this->editDiscountPrice !== '' ? $this->editDiscountPrice : null,
            'price_type' => $this->editPriceType,
            'duration_estimate_mins' => $this->editDurationEstimateMins,
            'cover_image' => $cover,
            'is_active' => $this->editIsActive,
            'location_required' => $this->editLocationRequired,
            'age_restriction' => $this->editAgeRestriction,
        ]);

        $this->showEditModal = false;
        $this->editCoverImageFile = null;
        $this->flashMessage = 'Service updated.';
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->editCoverImageFile = null;
        $this->resetValidation();
    }

    public function toggleActive(int $serviceId): void
    {
        if (! auth()->user()->hasPermission('services.manage')) {
            $this->addError('permission', 'You do not have permission to manage services.');
            return;
        }

        $service = Service::findOrFail($serviceId);
        $service->update(['is_active' => ! $service->is_active]);
    }

    // ============================== Delete ==============================

    public function confirmDelete(int $serviceId): void
    {
        $service = Service::withCount('bookings')->findOrFail($serviceId);

        // Unlike categories/subcategories this isn't a hard block: services use
        // soft deletes, so past bookings keep resolving their service through
        // the trashed row. Warn about the booking history rather than refuse.
        $this->deleteBlockedReason = '';
        $this->confirmingDeleteId = $serviceId;
        $this->deleteWarning = $service->bookings_count > 0
            ? 'This service has '.$service->bookings_count.' '.Str::plural('booking', $service->bookings_count).' against it. It will be hidden from the catalog, and that history stays intact.'
            : '';
    }

    public function deleteService(): void
    {
        if (! $this->confirmingDeleteId) {
            return;
        }

        if (! auth()->user()->hasPermission('services.manage')) {
            $this->addError('permission', 'You do not have permission to manage services.');
            $this->confirmingDeleteId = null;
            return;
        }

        // Soft delete: the file stays put deliberately, since restoring a
        // trashed service should bring its picture back with it.
        Service::findOrFail($this->confirmingDeleteId)->delete();

        $this->confirmingDeleteId = null;
        $this->deleteWarning = '';
        $this->flashMessage = 'Service deleted.';
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
        $this->deleteWarning = '';
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

    public function moveUp(int $serviceId): void
    {
        $this->swapWithNeighbour($serviceId, -1);
    }

    public function moveDown(int $serviceId): void
    {
        $this->swapWithNeighbour($serviceId, 1);
    }

    /**
     * Swap a service's position with the row above/below it *in the list as
     * currently filtered* — one flat sequence across every service, matching
     * Categories\Manage exactly. Filtering and then reordering within that
     * filter is the normal way to arrange a slice of the list, so the
     * neighbour comes from the filtered set rather than the global one.
     */
    private function swapWithNeighbour(int $serviceId, int $offset): void
    {
        if (! auth()->user()->hasPermission('services.manage')) {
            $this->addError('permission', 'You do not have permission to reorder services.');
            return;
        }

        $this->normalizeSortOrder();

        $ordered = $this->baseQuery()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'sort_order']);

        $index = $ordered->search(fn ($row) => $row->id === $serviceId);
        if ($index === false) {
            return;
        }

        $target = $ordered->get($index + $offset);
        if (! $target) {
            return; // already at the top/bottom of the list
        }

        $current = $ordered->get($index);

        DB::transaction(function () use ($current, $target) {
            Service::whereKey($current->id)->update(['sort_order' => $target->sort_order]);
            Service::whereKey($target->id)->update(['sort_order' => $current->sort_order]);
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
        $all = Service::orderBy('sort_order')->orderBy('id')->get(['id', 'sort_order']);

        $needsNormalising = $all->pluck('sort_order')->duplicates()->isNotEmpty()
            || $all->contains(fn ($row) => (int) $row->sort_order === 0);

        if (! $needsNormalising) {
            return;
        }

        DB::transaction(function () use ($all) {
            foreach ($all->values() as $position => $row) {
                Service::whereKey($row->id)->update(['sort_order' => $position + 1]);
            }
        });
    }

    // ============================= Import/export =============================
    // Delegates to ServiceImporter/CatalogImporter (mission Phase 14 —
    // Master Catalog Import) — Glover column aliases and the
    // subcategory-belongs-to-category check now live there, shared with
    // the same real pipeline Categories/Subcategories use.

    public function exportServices()
    {
        return Excel::download(new ServicesExport, 'services-'.now()->format('Y-m-d').'.xlsx');
    }

    public function downloadTemplate()
    {
        return Excel::download(new ServicesExport(templateOnly: true), 'services-template.xlsx');
    }

    /** Export Everywhere session, Part 1 — current filtered view as CSV. No franchise/zone scope on services (global catalog). */
    public function exportServicesCsv()
    {
        return $this->streamCsvExport(
            'services-filtered-'.now()->format('Y-m-d-His').'.csv',
            $this->baseQuery()->with(['category', 'subcategory']),
            ['id', 'name', 'category', 'subcategory', 'base_price', 'discount_price', 'is_active', 'sort_order', 'created_at'],
            fn (Service $s) => [$s->id, $s->name, $s->category?->name, $s->subcategory?->name, $s->base_price, $s->discount_price, $s->is_active ? 1 : 0, $s->sort_order, $s->created_at],
        );
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

    public function validateServicesImport(): void
    {
        $this->importErrors = [];
        $this->importRows = null;
        $this->importMessage = null;
        $this->importRun = null;

        $this->validate(['importFile' => ['required', 'file', 'mimes:xlsx,xls,csv']]);

        $reader = new HeadingRowImport;
        Excel::import($reader, $this->importFile->getRealPath());

        $result = (new ServiceImporter)->validateRows($reader->rows);

        if (! empty($result['errors'])) {
            $this->importErrors = $result['errors'];
            return;
        }

        $this->importRows = $result['previewRows'];
    }

    public function commitServicesImport(): void
    {
        if (empty($this->importRows)) {
            return;
        }

        if (! auth()->user()->hasPermission('services.manage')) {
            $this->importErrors = [['row' => '-', 'field' => 'permission', 'message' => 'You do not have permission to import services.']];
            return;
        }

        $fileName = $this->importFile?->getClientOriginalName();

        $this->importRun = (new ServiceImporter)->commit(
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
        return Service::query()
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->when($this->filterCategory !== '', fn ($q) => $q->where('category_id', $this->filterCategory))
            ->when($this->filterSubcategory !== '', fn ($q) => $q->where('subcategory_id', $this->filterSubcategory))
            ->when($this->filterModule !== '', fn ($q) => $q->whereHas('category', fn ($c) => $c->where('module', $this->filterModule)))
            ->when($this->filterActive !== '', fn ($q) => $q->where('is_active', $this->filterActive === 'active'));
    }

    public function render()
    {
        // One flat running order across all services, same as Categories.
        $services = $this->baseQuery()
            ->with(['category', 'subcategory'])
            ->orderBy($this->sortField, $this->sortDirection)
            ->orderBy('id')
            ->paginate($this->perPage);

        return view('livewire.services.manage', [
            'services' => $services,
            'categories' => ServiceCategory::orderBy('module')->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'module']),
            'modules' => Modules::options(),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('layouts.admin', ['title' => 'Services']);
    }
}
