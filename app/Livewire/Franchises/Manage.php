<?php

namespace App\Livewire\Franchises;

use App\Models\Franchise;
use App\Models\FranchiseModule;
use App\Models\Setting;
use App\Support\Modules;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

// Same one-screen pattern as the other Manage screens: add form pinned at the
// top, live list below, edit + read-only detail modals, one route.
//
// Two shape notes:
//
// - No Reorder. Franchises are operating units, not a merchandised list, and
//   `franchises` has no sort_order column — same reasoning as Zones.
//
// - The module toggles live in the edit modal only, not the add row. There
//   are eight of them and every vertical except Service is unbuilt, so a new
//   franchise gets Service on and the rest off; turning others on is a later,
//   deliberate act rather than something to decide while typing a city name.
class Manage extends Component
{
    use WithPagination;

    /**
     * Toggleable verticals, excluding `service` — that one is always on and
     * not editable, being the only vertical actually built. Keys match the
     * franchise_modules columns; labels come from the shared Modules list so
     * this screen and the catalog screens can't disagree on naming.
     *
     * NOTE: franchise_modules predates the Car Rental addition and has no
     * car_rental column, so it isn't offered here. See App\Support\Modules.
     */
    public const TOGGLEABLE = ['food', 'parcel', 'taxi', 'grocery', 'pharmacy', 'commerce', 'bookings'];

    // --- "Add New" form ---
    public string $name = '';
    public string $city = '';
    public string $state = '';
    public string $country = 'India';
    public string $commissionModel = 'revenue_share';
    public string $commissionValue = '0';
    public string $platformFeePercent = '0';
    public string $status = 'pending_setup';

    // --- Edit modal ---
    public bool $showEditModal = false;
    public ?int $editFranchiseId = null;
    public string $editName = '';
    public string $editCode = '';
    public string $editCity = '';
    public string $editState = '';
    public string $editCountry = 'India';
    public string $editCommissionModel = 'revenue_share';
    public string $editCommissionValue = '0';
    public string $editPlatformFeePercent = '0';
    public string $editStatus = 'pending_setup';
    /** slug => bool, for the toggleable verticals above. */
    public array $editModules = [];

    // --- View details modal ---
    public bool $showViewModal = false;
    public ?int $viewFranchiseId = null;

    // --- Delete confirmation ---
    public ?int $confirmingDeleteId = null;
    public string $deleteBlockedReason = '';

    // --- List controls ---
    public string $search = '';
    public string $filterStatus = '';
    public bool $showFilters = false;
    public string $sortField = 'name';
    public string $sortDirection = 'asc';
    public int $perPage = 10;

    public string $flashMessage = '';

    /**
     * Pre-fills the Add New form from the Settings screen's commission
     * defaults instead of the hardcoded literals below (kept as the
     * ultimate fallback for a fresh install with no Setting rows yet).
     * Re-read (not cached on the instance) in save()'s reset step too,
     * since Livewire only re-runs mount() on the first page load, not on
     * every subsequent action — Setting::get() itself is what's cached.
     */
    public function mount(): void
    {
        $this->commissionModel = Setting::get('commission.default_model', $this->commissionModel);
        $this->commissionValue = (string) Setting::get('commission.default_value', $this->commissionValue);
        $this->platformFeePercent = (string) Setting::get('commission.default_platform_fee_percent', $this->platformFeePercent);
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedFilterStatus(): void { $this->resetPage(); }
    public function updatedPerPage(): void { $this->resetPage(); }

    private function rules(string $prefix = ''): array
    {
        $f = fn (string $name) => $prefix === '' ? $name : $prefix.ucfirst($name);

        return [
            $f('name') => ['required', 'string', 'max:255'],
            $f('city') => ['required', 'string', 'max:255'],
            $f('state') => ['nullable', 'string', 'max:255'],
            $f('country') => ['required', 'string', 'max:255'],
            $f('commissionModel') => ['required', 'in:revenue_share,flat_fee,subscription_only'],
            $f('commissionValue') => ['required', 'numeric', 'min:0'],
            $f('platformFeePercent') => ['required', 'numeric', 'min:0', 'max:100'],
            $f('status') => ['required', 'in:active,inactive,pending_setup'],
        ];
    }

    private function attributes(string $prefix = ''): array
    {
        $f = fn (string $name) => $prefix === '' ? $name : $prefix.ucfirst($name);

        return [
            $f('commissionModel') => 'commission model',
            $f('commissionValue') => 'commission value',
            $f('platformFeePercent') => 'platform fee',
        ];
    }

    // ============================= Add New =============================

    public function save(): void
    {
        $this->validate($this->rules(), [], $this->attributes());

        // slug is required by the schema; FranchiseObserver auto-generates
        // `code` from the name (first 3 letters, numeric suffix on collision).
        $franchise = Franchise::create([
            'name' => $this->name,
            'slug' => Str::slug($this->name).'-'.Str::random(4),
            'city' => $this->city,
            'state' => $this->state ?: null,
            'country' => $this->country,
            'commission_model' => $this->commissionModel,
            'commission_value' => $this->commissionValue,
            'platform_fee_percent' => $this->platformFeePercent,
            'status' => $this->status,
        ]);

        // Service on, everything else off — the other verticals aren't built,
        // and switching one on is done deliberately from the edit modal.
        FranchiseModule::updateOrCreate(
            ['franchise_id' => $franchise->id],
            ['service' => true] + array_fill_keys(self::TOGGLEABLE, false)
        );

        $this->reset(['name', 'city', 'state']);
        $this->country = 'India';
        $this->commissionModel = Setting::get('commission.default_model', 'revenue_share');
        $this->commissionValue = (string) Setting::get('commission.default_value', '0');
        $this->platformFeePercent = (string) Setting::get('commission.default_platform_fee_percent', '0');
        $this->status = 'pending_setup';
        $this->flashMessage = 'Franchise created.';
    }

    // ============================== Edit modal ==============================

    public function edit(int $franchiseId): void
    {
        $franchise = Franchise::with('modules')->findOrFail($franchiseId);

        $this->editFranchiseId = $franchise->id;
        $this->editName = $franchise->name;
        $this->editCode = $franchise->code ?? '';
        $this->editCity = $franchise->city;
        $this->editState = $franchise->state ?? '';
        $this->editCountry = $franchise->country;
        $this->editCommissionModel = $franchise->commission_model;
        $this->editCommissionValue = (string) $franchise->commission_value;
        $this->editPlatformFeePercent = (string) $franchise->platform_fee_percent;
        $this->editStatus = $franchise->status;

        $this->editModules = collect(self::TOGGLEABLE)
            ->mapWithKeys(fn ($slug) => [$slug => (bool) ($franchise->modules->{$slug} ?? false)])
            ->all();

        $this->resetValidation();
        $this->showViewModal = false;
        $this->showEditModal = true;
    }

    public function update(): void
    {
        $this->validate($this->rules('edit'), [], $this->attributes('edit'));

        $franchise = Franchise::findOrFail($this->editFranchiseId);
        $franchise->update([
            'name' => $this->editName,
            'city' => $this->editCity,
            'state' => $this->editState ?: null,
            'country' => $this->editCountry,
            'commission_model' => $this->editCommissionModel,
            'commission_value' => $this->editCommissionValue,
            'platform_fee_percent' => $this->editPlatformFeePercent,
            'status' => $this->editStatus,
        ]);

        FranchiseModule::updateOrCreate(
            ['franchise_id' => $franchise->id],
            ['service' => true] + collect(self::TOGGLEABLE)
                ->mapWithKeys(fn ($slug) => [$slug => (bool) ($this->editModules[$slug] ?? false)])
                ->all()
        );

        $this->showEditModal = false;
        $this->flashMessage = 'Franchise updated.';
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->resetValidation();
    }

    /**
     * Cycle a franchise between active and inactive. `pending_setup` counts
     * as not-yet-live, so the first toggle activates it.
     */
    public function toggleStatus(int $franchiseId): void
    {
        $franchise = Franchise::findOrFail($franchiseId);
        $franchise->update(['status' => $franchise->status === 'active' ? 'inactive' : 'active']);
    }

    // ========================= View details (read-only) =========================

    public function view(int $franchiseId): void
    {
        $this->viewFranchiseId = $franchiseId;
        $this->showViewModal = true;
    }

    public function closeViewModal(): void
    {
        $this->showViewModal = false;
        $this->viewFranchiseId = null;
    }

    public function getViewingFranchiseProperty(): ?Franchise
    {
        if (! $this->viewFranchiseId) {
            return null;
        }

        return Franchise::with('modules')
            ->withCount(['zones', 'providers', 'bookings'])
            ->find($this->viewFranchiseId);
    }

    // ============================== Delete ==============================

    public function confirmDelete(int $franchiseId): void
    {
        $franchise = Franchise::withCount(['zones', 'providers', 'bookings'])->findOrFail($franchiseId);

        // Nearly every operational table carries franchise_id — deleting one
        // with anything under it would orphan real bookings and payouts.
        // Refuse and point at deactivation instead.
        $blockers = [];
        foreach (['zones' => 'zone', 'providers' => 'provider', 'bookings' => 'booking'] as $rel => $noun) {
            $n = $franchise->{$rel.'_count'};
            if ($n > 0) {
                $blockers[] = $n.' '.Str::plural($noun, $n);
            }
        }

        $this->deleteBlockedReason = $blockers
            ? 'This franchise still has '.implode(', ', $blockers).' attached. Set it to Inactive instead — deleting it would orphan operational records.'
            : '';

        $this->confirmingDeleteId = $franchiseId;
    }

    public function deleteFranchise(): void
    {
        if (! $this->confirmingDeleteId || $this->deleteBlockedReason) {
            return;
        }

        Franchise::findOrFail($this->confirmingDeleteId)->delete();

        $this->confirmingDeleteId = null;
        $this->flashMessage = 'Franchise deleted.';
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

        $this->resetPage();
    }

    public function render()
    {
        $franchises = Franchise::query()
            ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                $w->where('name', 'like', '%'.$this->search.'%')
                  ->orWhere('code', 'like', '%'.$this->search.'%')
                  ->orWhere('city', 'like', '%'.$this->search.'%');
            }))
            ->when($this->filterStatus !== '', fn ($q) => $q->where('status', $this->filterStatus))
            ->with('modules')
            ->withCount(['zones', 'providers', 'bookings'])
            ->orderBy($this->sortField, $this->sortDirection)
            ->orderBy('id')
            ->paginate($this->perPage);

        return view('livewire.franchises.manage', [
            'franchises' => $franchises,
            'toggleable' => collect(self::TOGGLEABLE)
                ->mapWithKeys(fn ($slug) => [$slug => Modules::label($slug)])
                ->all(),
        ])->layout('layouts.admin', ['title' => 'Franchises']);
    }
}
