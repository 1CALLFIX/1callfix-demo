<?php

namespace App\Livewire\Banners;

use App\Models\Banner;
use App\Models\Franchise;
use App\Models\ServiceCategory;
use App\Models\Setting;
use App\Models\Zone;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

// Same one-screen pattern as the catalog screens (Categories\Manage): inline
// add form pinned at the top, live list below, edit in a modal, one route.
//
// `banners` is not purely decorative — it doubles as the ad-revenue table.
// A row with a price_paid is a sold slot; a row without one is a house banner
// (our own promo). That distinction drives the Type filter and the revenue
// summary rather than a separate boolean, matching how the schema was built.
class Manage extends Component
{
    use WithFileUploads;
    use WithPagination;

    /**
     * This screen previously had no view-level check at all — only the
     * write actions (save/update/delete) checked banners.manage, meaning
     * any admin-panel actor of any role could browse it (Phase 11 audit
     * finding, same bug class addendum #1 already fixed on 15 other
     * screens). No separate banners.view permission was ever seeded, so
     * this reuses banners.manage — consistent with every write action
     * already requiring it.
     */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('banners.manage'), 403, 'You do not have permission to view banners.');
    }

    // --- "Add New" form ---
    public string $title = '';
    public $imageFile = null;
    public string $link = '';
    public string $placement = 'top';
    public string $module = '';
    public string $categoryId = '';
    public string $franchiseId = '';
    public string $zoneId = '';
    public string $startsAt = '';
    public string $expiresAt = '';
    public string $advertiserName = '';
    public string $advertiserContact = '';
    public string $pricePaid = '';
    public bool $isActive = true;

    // --- Edit modal ---
    public bool $showEditModal = false;
    public ?int $editBannerId = null;
    public string $editTitle = '';
    public $editImageFile = null;
    public ?string $editExistingImage = null;
    public string $editLink = '';
    public string $editPlacement = 'top';
    public string $editModule = '';
    public string $editCategoryId = '';
    public string $editFranchiseId = '';
    public string $editZoneId = '';
    public string $editStartsAt = '';
    public string $editExpiresAt = '';
    public string $editAdvertiserName = '';
    public string $editAdvertiserContact = '';
    public string $editPricePaid = '';
    public bool $editIsActive = true;

    // --- View details modal ---
    public bool $showViewModal = false;
    public ?int $viewBannerId = null;

    // --- Delete confirmation ---
    public ?int $confirmingDeleteId = null;

    // --- List controls ---
    public string $search = '';
    public string $filterFranchise = '';
    public string $filterZone = '';
    public string $filterCategory = '';
    public string $filterPlacement = ''; // top | mid
    public string $filterModule = '';
    public string $filterStatus = '';   // live | scheduled | expired | inactive
    public string $filterType = '';     // paid | house
    public bool $showFilters = false;
    public string $sortField = 'sort_order';
    public string $sortDirection = 'asc';
    public int $perPage = 10;
    public bool $reorderMode = false;

    public string $flashMessage = '';

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedFilterZone(): void { $this->resetPage(); }
    public function updatedFilterCategory(): void { $this->resetPage(); }
    public function updatedFilterPlacement(): void { $this->resetPage(); }
    public function updatedFilterModule(): void { $this->resetPage(); }
    public function updatedFilterStatus(): void { $this->resetPage(); }
    public function updatedFilterType(): void { $this->resetPage(); }
    public function updatedPerPage(): void { $this->resetPage(); }

    public function updatedFilterFranchise(): void
    {
        // A zone from a different franchise would contradict the new franchise
        // filter and return nothing — clear it.
        $this->filterZone = '';
        $this->resetPage();
    }

    // Zones belong to a franchise, so picking one narrows the other. Blank
    // franchise means the banner runs everywhere, which also means no zone.
    public function updatedFranchiseId(): void
    {
        $this->zoneId = '';
    }

    public function updatedEditFranchiseId(): void
    {
        $this->editZoneId = '';
    }

    // A category already belongs to a module, so the two can contradict each
    // other ("Food Delivery" + an AC Repair category = matches nothing).
    // Rather than validate that and make the admin fix it, keep them in sync:
    // choosing a category adopts its module, and changing the module drops a
    // category that no longer fits.
    public function updatedCategoryId(): void
    {
        if ($this->categoryId === '') {
            return;
        }

        $this->module = ServiceCategory::find($this->categoryId)?->module ?? $this->module;
    }

    public function updatedModule(): void
    {
        if ($this->categoryId === '') {
            return;
        }

        $category = ServiceCategory::find($this->categoryId);
        if ($this->module !== '' && $category && $category->module !== $this->module) {
            $this->categoryId = '';
        }
    }

    public function updatedEditCategoryId(): void
    {
        if ($this->editCategoryId === '') {
            return;
        }

        $this->editModule = ServiceCategory::find($this->editCategoryId)?->module ?? $this->editModule;
    }

    public function updatedEditModule(): void
    {
        if ($this->editCategoryId === '') {
            return;
        }

        $category = ServiceCategory::find($this->editCategoryId);
        if ($this->editModule !== '' && $category && $category->module !== $this->editModule) {
            $this->editCategoryId = '';
        }
    }

    public function getZonesProperty()
    {
        return $this->zonesFor($this->franchiseId);
    }

    public function getEditZonesProperty()
    {
        return $this->zonesFor($this->editFranchiseId);
    }

    public function getFilterZonesProperty()
    {
        return $this->zonesFor($this->filterFranchise);
    }

    private function zonesFor(string $franchiseId)
    {
        if ($franchiseId === '') {
            return collect();
        }

        return Zone::where('franchise_id', $franchiseId)->orderBy('name')->get();
    }

    /**
     * A banner's franchise_id is a targeting axis, not just ownership: blank
     * means "runs everywhere" (platform-wide reach), a specific id means
     * "only that franchise". So the permission check mirrors that exactly —
     * a franchise-scoped banners.manage holder can manage banners targeted
     * at their own franchise, but only a global grant can create or touch a
     * platform-wide one. Same shape as Zones\Manage::franchiseScope().
     */
    private function targetScope(?int $franchiseId): array
    {
        if (! $franchiseId) {
            return [];
        }

        $franchise = Franchise::find($franchiseId);

        return array_filter([
            'franchise_id' => $franchise?->id,
            'city_id' => $franchise?->city_id,
            'country_id' => $franchise?->country_id,
        ]);
    }

    // ============================= Add New =============================

    public function save(): void
    {
        $this->validate($this->rules(), $this->messages(), $this->attributes());

        if (! auth()->user()->hasPermission('banners.manage', $this->targetScope($this->franchiseId ? (int) $this->franchiseId : null))) {
            $this->addError('permission', 'You do not have permission to create a banner for this target.');
            return;
        }

        Banner::create([
            'title' => $this->title,
            'image' => $this->storeImage($this->imageFile),
            'link' => $this->link ?: null,
            'placement' => $this->placement,
            'module' => $this->module ?: null,
            'category_id' => $this->categoryId ?: null,
            'franchise_id' => $this->franchiseId ?: null,
            'zone_id' => $this->zoneId ?: null,
            'starts_at' => $this->startsAt ?: null,
            'expires_at' => $this->expiresAt ?: null,
            'advertiser_name' => $this->advertiserName ?: null,
            'advertiser_contact' => $this->advertiserContact ?: null,
            // Left blank = house banner, not a sold slot. Deliberately null
            // rather than 0 so "free" and "unsold" stay distinguishable.
            'price_paid' => $this->pricePaid !== '' ? $this->pricePaid : null,
            'is_active' => $this->isActive,
            'sort_order' => (int) Banner::max('sort_order') + 1,
        ]);

        // placement/franchise/zone/category are deliberately kept — adding a
        // run of banners for the same slot and target is the common case.
        $this->reset([
            'title', 'imageFile', 'link', 'startsAt', 'expiresAt',
            'advertiserName', 'advertiserContact', 'pricePaid', 'isActive',
        ]);
        $this->isActive = true;
        $this->flashMessage = 'Banner created.';
    }

    /**
     * Shared by add and edit — the `edit` prefix is applied by the caller so
     * the two forms can't drift apart on rules.
     */
    private function rules(string $prefix = ''): array
    {
        $f = fn (string $name) => $prefix === '' ? $name : $prefix.ucfirst($name);

        return [
            $f('title') => ['required', 'string', 'max:255'],
            // Required on create; on edit only when there's no image yet.
            $f('imageFile') => [
                $prefix !== '' && $this->editExistingImage ? 'nullable' : 'required',
                'image', 'mimes:png,jpg,jpeg', 'max:2048',
            ],
            $f('link') => ['nullable', 'url', 'max:2048'],
            $f('placement') => ['required', Rule::in(array_keys(Banner::PLACEMENTS))],
            $f('module') => ['nullable', Rule::in(Modules::slugs())],
            $f('categoryId') => ['nullable', 'exists:service_categories,id'],
            $f('franchiseId') => ['nullable', 'exists:franchises,id'],
            $f('zoneId') => ['nullable', 'exists:zones,id'],
            $f('startsAt') => ['nullable', 'date'],
            // A window that ends before it starts is always a typo.
            $f('expiresAt') => ['nullable', 'date', 'after_or_equal:'.$f('startsAt')],
            $f('advertiserName') => ['nullable', 'string', 'max:255'],
            $f('advertiserContact') => ['nullable', 'string', 'max:255'],
            $f('pricePaid') => ['nullable', 'numeric', 'min:0'],
        ];
    }

    private function messages(string $prefix = ''): array
    {
        $f = fn (string $name) => $prefix === '' ? $name : $prefix.ucfirst($name);

        return [
            $f('imageFile').'.required' => 'Please choose a banner image (PNG or JPEG).',
            $f('imageFile').'.max' => 'The banner image must be 2 MB or smaller.',
            $f('link').'.url' => 'The link must be a full URL, including https://',
            $f('expiresAt').'.after_or_equal' => 'The end date cannot be before the start date.',
        ];
    }

    private function attributes(string $prefix = ''): array
    {
        $f = fn (string $name) => $prefix === '' ? $name : $prefix.ucfirst($name);

        return [
            $f('imageFile') => 'image',
            $f('franchiseId') => 'franchise',
            $f('zoneId') => 'zone',
            $f('startsAt') => 'start date',
            $f('expiresAt') => 'end date',
            $f('advertiserName') => 'advertiser name',
            $f('advertiserContact') => 'advertiser contact',
            $f('pricePaid') => 'price paid',
        ];
    }

    private function storeImage($file): string
    {
        return $file->store('banners', 'public');
    }

    private function deleteStoredImage(?string $image): void
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

    public function edit(int $bannerId): void
    {
        $banner = Banner::findOrFail($bannerId);

        $this->editBannerId = $banner->id;
        $this->editTitle = $banner->title;
        $this->editImageFile = null;
        $this->editExistingImage = $banner->image;
        $this->editLink = $banner->link ?? '';
        $this->editPlacement = $banner->placement ?? 'top';
        $this->editModule = $banner->module ?? '';
        $this->editCategoryId = $banner->category_id ? (string) $banner->category_id : '';
        $this->editFranchiseId = $banner->franchise_id ? (string) $banner->franchise_id : '';
        $this->editZoneId = $banner->zone_id ? (string) $banner->zone_id : '';
        // datetime-local inputs want Y-m-d\TH:i, not Eloquent's default format.
        $this->editStartsAt = $banner->starts_at?->format('Y-m-d\TH:i') ?? '';
        $this->editExpiresAt = $banner->expires_at?->format('Y-m-d\TH:i') ?? '';
        $this->editAdvertiserName = $banner->advertiser_name ?? '';
        $this->editAdvertiserContact = $banner->advertiser_contact ?? '';
        $this->editPricePaid = $banner->price_paid !== null ? (string) $banner->price_paid : '';
        $this->editIsActive = $banner->is_active;

        $this->resetValidation();
        $this->showViewModal = false;
        $this->showEditModal = true;
    }

    public function update(): void
    {
        $this->validate($this->rules('edit'), $this->messages('edit'), $this->attributes('edit'));

        $banner = Banner::findOrFail($this->editBannerId);

        // Checked against the banner's CURRENT target, not the one it's
        // being edited to — same "authority over where it lives right now"
        // rule as Zones\Manage::update().
        if (! auth()->user()->hasPermission('banners.manage', $this->targetScope($banner->franchise_id))) {
            $this->addError('permission', 'You do not have permission to edit this banner.');
            return;
        }

        $image = $banner->image;
        if ($this->editImageFile) {
            $image = $this->storeImage($this->editImageFile);
            $this->deleteStoredImage($banner->image);
        }

        $banner->update([
            'title' => $this->editTitle,
            'image' => $image,
            'link' => $this->editLink ?: null,
            'placement' => $this->editPlacement,
            'module' => $this->editModule ?: null,
            'category_id' => $this->editCategoryId ?: null,
            'franchise_id' => $this->editFranchiseId ?: null,
            'zone_id' => $this->editZoneId ?: null,
            'starts_at' => $this->editStartsAt ?: null,
            'expires_at' => $this->editExpiresAt ?: null,
            'advertiser_name' => $this->editAdvertiserName ?: null,
            'advertiser_contact' => $this->editAdvertiserContact ?: null,
            'price_paid' => $this->editPricePaid !== '' ? $this->editPricePaid : null,
            'is_active' => $this->editIsActive,
        ]);

        $this->showEditModal = false;
        $this->editImageFile = null;
        $this->flashMessage = 'Banner updated.';
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->editImageFile = null;
        $this->resetValidation();
    }

    public function toggleActive(int $bannerId): void
    {
        $banner = Banner::findOrFail($bannerId);

        if (! auth()->user()->hasPermission('banners.manage', $this->targetScope($banner->franchise_id))) {
            $this->addError('permission', 'You do not have permission to change this banner.');
            return;
        }

        $banner->update(['is_active' => ! $banner->is_active]);
    }

    // ========================= View details (read-only) =========================

    public function view(int $bannerId): void
    {
        $this->viewBannerId = $bannerId;
        $this->showViewModal = true;
    }

    public function closeViewModal(): void
    {
        $this->showViewModal = false;
        $this->viewBannerId = null;
    }

    public function getViewingBannerProperty(): ?Banner
    {
        if (! $this->viewBannerId) {
            return null;
        }

        return Banner::with(['franchise.country', 'zone'])->find($this->viewBannerId);
    }

    // ============================== Delete ==============================

    public function confirmDelete(int $bannerId): void
    {
        $this->confirmingDeleteId = $bannerId;
    }

    public function deleteBanner(): void
    {
        if (! $this->confirmingDeleteId) {
            return;
        }

        // banners has no soft deletes and nothing references it, so this is a
        // real delete — take the image with it rather than orphaning the file.
        $banner = Banner::findOrFail($this->confirmingDeleteId);

        if (! auth()->user()->hasPermission('banners.manage', $this->targetScope($banner->franchise_id))) {
            $this->addError('permission', 'You do not have permission to delete this banner.');
            $this->confirmingDeleteId = null;
            return;
        }

        $image = $banner->image;
        $banner->delete();
        $this->deleteStoredImage($image);

        $this->confirmingDeleteId = null;
        $this->flashMessage = 'Banner deleted.';
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
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
    // Flat and global, same as every other admin screen — the arrows swap with
    // the immediate neighbour in the currently filtered list.

    public function toggleReorder(): void
    {
        $this->reorderMode = ! $this->reorderMode;

        if ($this->reorderMode) {
            $this->sortField = 'sort_order';
            $this->sortDirection = 'asc';
        }
    }

    public function moveUp(int $bannerId): void
    {
        $this->swapWithNeighbour($bannerId, -1);
    }

    public function moveDown(int $bannerId): void
    {
        $this->swapWithNeighbour($bannerId, 1);
    }

    private function swapWithNeighbour(int $bannerId, int $offset): void
    {
        $banner = Banner::find($bannerId);

        if (! $banner || ! auth()->user()->hasPermission('banners.manage', $this->targetScope($banner->franchise_id))) {
            $this->addError('permission', 'You do not have permission to reorder this banner.');
            return;
        }

        $this->normalizeSortOrder();

        $ordered = $this->baseQuery()->orderBy('sort_order')->orderBy('id')->get(['id', 'sort_order']);

        $index = $ordered->search(fn ($row) => $row->id === $bannerId);
        if ($index === false) {
            return;
        }

        $target = $ordered->get($index + $offset);
        if (! $target) {
            return; // already at the top/bottom of the list
        }

        $current = $ordered->get($index);

        DB::transaction(function () use ($current, $target) {
            Banner::whereKey($current->id)->update(['sort_order' => $target->sort_order]);
            Banner::whereKey($target->id)->update(['sort_order' => $current->sort_order]);
        });
    }

    private function normalizeSortOrder(): void
    {
        $all = Banner::orderBy('sort_order')->orderBy('id')->get(['id', 'sort_order']);

        $needsNormalising = $all->pluck('sort_order')->duplicates()->isNotEmpty()
            || $all->contains(fn ($row) => (int) $row->sort_order === 0);

        if (! $needsNormalising) {
            return;
        }

        DB::transaction(function () use ($all) {
            foreach ($all->values() as $position => $row) {
                Banner::whereKey($row->id)->update(['sort_order' => $position + 1]);
            }
        });
    }

    // ============================== Query ==============================

    private function baseQuery()
    {
        $now = now();

        return Banner::query()
            ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                $w->where('title', 'like', '%'.$this->search.'%')
                  ->orWhere('advertiser_name', 'like', '%'.$this->search.'%');
            }))
            ->when($this->filterFranchise !== '', fn ($q) => $q->where('franchise_id', $this->filterFranchise))
            ->when($this->filterZone !== '', fn ($q) => $q->where('zone_id', $this->filterZone))
            ->when($this->filterCategory !== '', fn ($q) => $q->where('category_id', $this->filterCategory))
            ->when($this->filterPlacement !== '', fn ($q) => $q->where('placement', $this->filterPlacement))
            ->when($this->filterModule !== '', fn ($q) => $q->where('module', $this->filterModule))
            ->when($this->filterType === 'paid', fn ($q) => $q->whereNotNull('price_paid'))
            ->when($this->filterType === 'house', fn ($q) => $q->whereNull('price_paid'))
            // Status mirrors the badge shown on each row, so filtering by it
            // returns exactly the rows carrying that badge.
            ->when($this->filterStatus === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($this->filterStatus === 'live', fn ($q) => $q->currentlyLive())
            ->when($this->filterStatus === 'scheduled', fn ($q) => $q->where('is_active', true)
                ->whereNotNull('starts_at')->where('starts_at', '>', $now))
            ->when($this->filterStatus === 'expired', fn ($q) => $q->where('is_active', true)
                ->whereNotNull('expires_at')->where('expires_at', '<', $now));
    }

    public function render()
    {
        $banners = $this->baseQuery()
            ->with(['franchise.country', 'zone', 'category'])
            ->orderBy($this->sortField, $this->sortDirection)
            ->orderBy('id')
            ->paginate($this->perPage);

        // Revenue is split by slot because the two are sold at different
        // rates — a single combined total would hide which inventory is
        // actually earning.
        $revenueByPlacement = Banner::whereNotNull('price_paid')
            ->groupBy('placement')
            ->selectRaw('placement, sum(price_paid) as total, count(*) as slots')
            ->get()
            ->keyBy('placement');

        return view('livewire.banners.manage', [
            'banners' => $banners,
            'franchises' => Franchise::orderBy('name')->get(['id', 'name']),
            'categories' => ServiceCategory::orderBy('module')->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'module']),
            'placements' => Banner::PLACEMENTS,
            'modules' => Modules::options(),
            'liveCount' => Banner::currentlyLive()->count(),
            'liveByPlacement' => Banner::currentlyLive()
                ->groupBy('placement')
                ->selectRaw('placement, count(*) as total')
                ->pluck('total', 'placement'),
            'expiringSoon' => Banner::expiringSoon(7)->count(),
            'revenueByPlacement' => $revenueByPlacement,
            'paidRevenue' => (float) Banner::whereNotNull('price_paid')->sum('price_paid'),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('layouts.admin', ['title' => 'Banners']);
    }
}
