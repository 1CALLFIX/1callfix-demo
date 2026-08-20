<?php

namespace App\Livewire\Franchises;

use App\Models\City;
use App\Models\Country;
use App\Models\Franchise;
use App\Models\FranchiseModule;
use App\Models\Setting;
use App\Services\AuthorizationService;
use App\Support\Modules;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

// Same one-screen pattern as the other Manage screens: add form pinned at the
// top, live list below, edit + read-only detail modals, one route.
//
// Shape notes:
//
// - No Reorder. Franchises are operating units, not a merchandised list, and
//   `franchises` has no sort_order column — same reasoning as Zones.
//
// - The module toggles live in the edit modal only, not the add row. There
//   are eight of them and every vertical except Service is unbuilt, so a new
//   franchise gets Service on and the rest off; turning others on is a later,
//   deliberate act rather than something to decide while typing a city name.
//
// - Country/City are dropdowns backed by real tables (not free text) as of
//   the geography foundation work — cascading the same way Services'
//   category/subcategory picker does. Each carries a small inline "+ New"
//   form (same pattern as the New Booking modal's customer/address quick-add)
//   since there's nowhere else yet to create a Country or City.
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
    public ?int $countryId = null;
    public ?int $cityId = null;
    public string $state = '';
    public string $commissionModel = 'revenue_share';
    public string $commissionValue = '0';
    public string $platformFeePercent = '0';
    public string $status = 'pending_setup';

    // --- Add New: inline quick-add for Country/City ---
    public bool $showNewCountryForm = false;
    public string $newCountryName = '';
    public string $newCountryCode = '';
    public string $newCountryCurrencyCode = 'INR';
    public string $newCountryTimezone = 'Asia/Kolkata';
    public bool $showNewCityForm = false;
    public string $newCityName = '';

    // --- Edit modal ---
    public bool $showEditModal = false;
    public ?int $editFranchiseId = null;
    public string $editName = '';
    public string $editCode = '';
    public ?int $editCountryId = null;
    public ?int $editCityId = null;
    public string $editState = '';
    public string $editCommissionModel = 'revenue_share';
    public string $editCommissionValue = '0';
    public string $editPlatformFeePercent = '0';
    public string $editStatus = 'pending_setup';
    /** slug => bool, for the toggleable verticals above. */
    public array $editModules = [];

    // --- Edit modal: owner assignment (needed for SettlementService to have
    // someone real to credit the franchise's revenue share to; edit-only,
    // same reasoning as module toggles above — not a decision to make while
    // still typing the franchise's name). ---
    public string $editOwnerSearch = '';
    public ?int $editOwnerUserId = null;

    // --- Edit modal: inline quick-add for Country/City ---
    public bool $showEditNewCountryForm = false;
    public string $editNewCountryName = '';
    public string $editNewCountryCode = '';
    public string $editNewCountryCurrencyCode = 'INR';
    public string $editNewCountryTimezone = 'Asia/Kolkata';
    public bool $showEditNewCityForm = false;
    public string $editNewCityName = '';

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
        // No view-level check existed at all — only write actions checked
        // franchises.manage (Phase 11 audit finding, same bug class
        // addendum #1 already fixed on 15 other screens).
        abort_unless(auth()->user()->hasPermissionAnywhere('franchises.manage'), 403, 'You do not have permission to view franchises.');

        $this->commissionModel = Setting::get('commission.default_model', $this->commissionModel);
        $this->commissionValue = (string) Setting::get('commission.default_value', $this->commissionValue);
        $this->platformFeePercent = (string) Setting::get('commission.default_platform_fee_percent', $this->platformFeePercent);
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedFilterStatus(): void { $this->resetPage(); }
    public function updatedPerPage(): void { $this->resetPage(); }

    /** Changing the country invalidates whatever city was picked for the old one. */
    public function updatedCountryId(): void { $this->cityId = null; }
    public function updatedEditCountryId(): void { $this->editCityId = null; }

    private function rules(string $prefix = ''): array
    {
        $f = fn (string $name) => $prefix === '' ? $name : $prefix.ucfirst($name);

        return [
            $f('name') => ['required', 'string', 'max:255'],
            $f('countryId') => ['required', 'integer', 'exists:countries,id'],
            $f('cityId') => ['required', 'integer', 'exists:cities,id'],
            $f('state') => ['nullable', 'string', 'max:255'],
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
            $f('countryId') => 'country',
            $f('cityId') => 'city',
            $f('commissionModel') => 'commission model',
            $f('commissionValue') => 'commission value',
            $f('platformFeePercent') => 'platform fee',
        ];
    }

    // ============================= Add New =============================

    public function save(): void
    {
        $this->validate($this->rules(), [], $this->attributes());

        // A Country Admin may create franchises in their own country; a
        // Zone/City/Franchise-scoped role has no franchises.manage grant
        // that covers this (there's no franchise yet to scope to), so only
        // global (Super Admin) or the matching country_id assignment passes.
        // Livewire's own error bag rather than abort() -- an HTTP abort
        // inside a component action doesn't round-trip as a normal PHP
        // exception through Livewire::test()'s in-process call lifecycle,
        // and addError() is the idiom the rest of this component already
        // uses for validate()'s own failures.
        if (! auth()->user()->hasPermission('franchises.manage', ['country_id' => $this->countryId])) {
            $this->addError('permission', 'You do not have permission to create a franchise in this country.');
            return;
        }

        // slug is required by the schema; FranchiseObserver auto-generates
        // `code` from the name (first 3 letters, numeric suffix on collision).
        $franchise = Franchise::create([
            'name' => $this->name,
            'slug' => Str::slug($this->name).'-'.Str::random(4),
            'country_id' => $this->countryId,
            'city_id' => $this->cityId,
            'state' => $this->state ?: null,
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

        $this->reset(['name', 'countryId', 'cityId', 'state']);
        $this->commissionModel = Setting::get('commission.default_model', 'revenue_share');
        $this->commissionValue = (string) Setting::get('commission.default_value', '0');
        $this->platformFeePercent = (string) Setting::get('commission.default_platform_fee_percent', '0');
        $this->status = 'pending_setup';
        $this->flashMessage = 'Franchise created.';
    }

    // ------------------------- Add New: quick-add Country/City -------------------------

    public function toggleNewCountryForm(): void
    {
        $this->showNewCountryForm = ! $this->showNewCountryForm;
        $this->reset(['newCountryName', 'newCountryCode']);
        $this->newCountryCurrencyCode = 'INR';
        $this->newCountryTimezone = 'Asia/Kolkata';
        $this->resetValidation(['newCountryName', 'newCountryCode', 'newCountryCurrencyCode', 'newCountryTimezone']);
    }

    public function createCountry(): void
    {
        $this->validate([
            'newCountryName' => ['required', 'string', 'max:255'],
            'newCountryCode' => ['required', 'string', 'size:2', 'unique:countries,code'],
            'newCountryCurrencyCode' => ['required', 'string', 'size:3'],
            'newCountryTimezone' => ['required', 'string', 'max:64'],
        ], [], [
            'newCountryName' => 'name', 'newCountryCode' => 'code',
            'newCountryCurrencyCode' => 'currency code', 'newCountryTimezone' => 'timezone',
        ]);

        $country = Country::create([
            'name' => $this->newCountryName,
            'code' => strtoupper($this->newCountryCode),
            'currency_code' => strtoupper($this->newCountryCurrencyCode),
            'default_timezone' => $this->newCountryTimezone,
            'is_active' => true,
        ]);

        $this->countryId = $country->id;
        $this->cityId = null;
        $this->showNewCountryForm = false;
    }

    public function toggleNewCityForm(): void
    {
        if (! $this->countryId) {
            $this->addError('countryId', 'Pick a country first — the new city is placed inside it.');
            return;
        }

        $this->showNewCityForm = ! $this->showNewCityForm;
        $this->newCityName = '';
        $this->resetValidation(['newCityName', 'countryId']);
    }

    public function createCity(): void
    {
        $this->validate([
            'countryId' => ['required', 'integer', 'exists:countries,id'],
            'newCityName' => ['required', 'string', 'max:255'],
        ], [], ['countryId' => 'country', 'newCityName' => 'city']);

        $city = City::create(['country_id' => $this->countryId, 'name' => $this->newCityName, 'is_active' => true]);

        $this->cityId = $city->id;
        $this->showNewCityForm = false;
    }

    // ============================== Edit modal ==============================

    public function edit(int $franchiseId): void
    {
        $franchise = Franchise::with('modules')->findOrFail($franchiseId);

        $this->editFranchiseId = $franchise->id;
        $this->editName = $franchise->name;
        $this->editCode = $franchise->code ?? '';
        $this->editCountryId = $franchise->country_id;
        $this->editCityId = $franchise->city_id;
        $this->editState = $franchise->state ?? '';
        $this->editCommissionModel = $franchise->commission_model;
        $this->editCommissionValue = (string) $franchise->commission_value;
        $this->editPlatformFeePercent = (string) $franchise->platform_fee_percent;
        $this->editStatus = $franchise->status;

        $this->editModules = collect(self::TOGGLEABLE)
            ->mapWithKeys(fn ($slug) => [$slug => (bool) ($franchise->modules->{$slug} ?? false)])
            ->all();

        $this->editOwnerUserId = $franchise->owner_user_id;
        $this->editOwnerSearch = $franchise->owner?->name ?? '';

        $this->showEditNewCountryForm = false;
        $this->showEditNewCityForm = false;
        $this->resetValidation();
        $this->showViewModal = false;
        $this->showEditModal = true;
    }

    public function update(): void
    {
        $this->validate($this->rules('edit'), [], $this->attributes('edit'));

        $franchise = Franchise::findOrFail($this->editFranchiseId);

        // Was completely unguarded (found during the RBAC_SCOPE_MATRIX.md
        // read-through) -- save() has always checked franchises.manage but
        // update()/toggleStatus()/deleteFranchise() never did, meaning any
        // authenticated admin-panel actor could edit commission rates,
        // toggle status, or delete a franchise with zero permission check.
        // Checked against the franchise's CURRENT country -- same "authority
        // over where it lives now" rule as every other edit-action check
        // this program added (Zones/Banners).
        if (! auth()->user()->hasPermission('franchises.manage', ['country_id' => $franchise->country_id])) {
            $this->addError('permission', 'You do not have permission to edit this franchise.');
            return;
        }

        $franchise->update([
            'name' => $this->editName,
            'country_id' => $this->editCountryId,
            'city_id' => $this->editCityId,
            'state' => $this->editState ?: null,
            'owner_user_id' => $this->editOwnerUserId,
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

    public function getMatchingOwnersProperty()
    {
        if (mb_strlen($this->editOwnerSearch) < 2) {
            return collect();
        }

        return \App\Models\User::where('name', 'like', "%{$this->editOwnerSearch}%")
            ->orWhere('phone', 'like', "%{$this->editOwnerSearch}%")
            ->orderBy('name')
            ->limit(8)
            ->get();
    }

    public function selectEditOwner(int $userId): void
    {
        $this->editOwnerUserId = $userId;
        $this->editOwnerSearch = \App\Models\User::find($userId)?->name ?? '';
    }

    public function clearEditOwner(): void
    {
        $this->editOwnerUserId = null;
        $this->editOwnerSearch = '';
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->resetValidation();
    }

    // ------------------------- Edit modal: quick-add Country/City -------------------------

    public function toggleEditNewCountryForm(): void
    {
        $this->showEditNewCountryForm = ! $this->showEditNewCountryForm;
        $this->reset(['editNewCountryName', 'editNewCountryCode']);
        $this->editNewCountryCurrencyCode = 'INR';
        $this->editNewCountryTimezone = 'Asia/Kolkata';
        $this->resetValidation(['editNewCountryName', 'editNewCountryCode', 'editNewCountryCurrencyCode', 'editNewCountryTimezone']);
    }

    public function createEditCountry(): void
    {
        $this->validate([
            'editNewCountryName' => ['required', 'string', 'max:255'],
            'editNewCountryCode' => ['required', 'string', 'size:2', 'unique:countries,code'],
            'editNewCountryCurrencyCode' => ['required', 'string', 'size:3'],
            'editNewCountryTimezone' => ['required', 'string', 'max:64'],
        ], [], [
            'editNewCountryName' => 'name', 'editNewCountryCode' => 'code',
            'editNewCountryCurrencyCode' => 'currency code', 'editNewCountryTimezone' => 'timezone',
        ]);

        $country = Country::create([
            'name' => $this->editNewCountryName,
            'code' => strtoupper($this->editNewCountryCode),
            'currency_code' => strtoupper($this->editNewCountryCurrencyCode),
            'default_timezone' => $this->editNewCountryTimezone,
            'is_active' => true,
        ]);

        $this->editCountryId = $country->id;
        $this->editCityId = null;
        $this->showEditNewCountryForm = false;
    }

    public function toggleEditNewCityForm(): void
    {
        if (! $this->editCountryId) {
            $this->addError('editCountryId', 'Pick a country first — the new city is placed inside it.');
            return;
        }

        $this->showEditNewCityForm = ! $this->showEditNewCityForm;
        $this->editNewCityName = '';
        $this->resetValidation(['editNewCityName', 'editCountryId']);
    }

    public function createEditCity(): void
    {
        $this->validate([
            'editCountryId' => ['required', 'integer', 'exists:countries,id'],
            'editNewCityName' => ['required', 'string', 'max:255'],
        ], [], ['editCountryId' => 'country', 'editNewCityName' => 'city']);

        $city = City::create(['country_id' => $this->editCountryId, 'name' => $this->editNewCityName, 'is_active' => true]);

        $this->editCityId = $city->id;
        $this->showEditNewCityForm = false;
    }

    /**
     * Cycle a franchise between active and inactive. `pending_setup` counts
     * as not-yet-live, so the first toggle activates it.
     */
    public function toggleStatus(int $franchiseId): void
    {
        $franchise = Franchise::findOrFail($franchiseId);

        if (! auth()->user()->hasPermission('franchises.manage', ['country_id' => $franchise->country_id])) {
            $this->addError('permission', 'You do not have permission to change this franchise.');
            return;
        }

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

        return Franchise::with(['modules', 'city.country'])
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

        $franchise = Franchise::findOrFail($this->confirmingDeleteId);

        if (! auth()->user()->hasPermission('franchises.manage', ['country_id' => $franchise->country_id])) {
            $this->addError('permission', 'You do not have permission to delete this franchise.');
            $this->confirmingDeleteId = null;
            return;
        }

        $franchise->delete();

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

    /**
     * Admin Command Center completion session, Geography + Maps phase
     * (2026-08-20) -- every mutation on this screen (update()/toggleStatus()/
     * deleteFranchise(), plus save()) checks hasPermission('franchises.manage',
     * ['country_id' => ...]) ONLY -- no city_id/zone_id/franchise_id key is
     * ever passed, meaning this permission is deliberately country-admin-
     * and-above only by construction (AuthorizationService::can()'s own
     * docblock: "an assignment whose scope_type has no matching key in
     * $scope simply never covers the request" -- a city/zone-scoped grant
     * could never satisfy a country_id-only check). render() never applied
     * ANY row-level scope though, the same read-only-list gap already
     * found and fixed for zones.manage/banners.manage/notification.view_logs
     * this session -- closed here with the identical country_id-only shape
     * the writes already use, so the list and the write actions agree on
     * exactly who "franchises.manage" means, not a new, wider definition.
     */
    public function render()
    {
        $franchises = app(AuthorizationService::class)
            ->scopeQuery(Franchise::query(), auth()->user(), 'franchises.manage', ['country_id' => 'country_id'])
            ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                $w->where('name', 'like', '%'.$this->search.'%')
                  ->orWhere('code', 'like', '%'.$this->search.'%')
                  ->orWhereHas('city', fn ($c) => $c->where('name', 'like', '%'.$this->search.'%'));
            }))
            ->when($this->filterStatus !== '', fn ($q) => $q->where('status', $this->filterStatus))
            ->with(['modules', 'city', 'country'])
            ->withCount(['zones', 'providers', 'bookings'])
            ->orderBy($this->sortField, $this->sortDirection)
            ->orderBy('id')
            ->paginate($this->perPage);

        return view('livewire.franchises.manage', [
            'franchises' => $franchises,
            'countries' => Country::where('is_active', true)->orderBy('name')->get(),
            'cities' => $this->countryId
                ? City::where('country_id', $this->countryId)->where('is_active', true)->orderBy('name')->get()
                : collect(),
            'editCities' => $this->editCountryId
                ? City::where('country_id', $this->editCountryId)->where('is_active', true)->orderBy('name')->get()
                : collect(),
            'toggleable' => collect(self::TOGGLEABLE)
                ->mapWithKeys(fn ($slug) => [$slug => Modules::label($slug)])
                ->all(),
        ])->layout('layouts.admin', ['title' => 'Franchises']);
    }
}
