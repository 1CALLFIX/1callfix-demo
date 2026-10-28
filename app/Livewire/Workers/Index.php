<?php

namespace App\Livewire\Workers;

use App\Livewire\Concerns\HasRowArchive;
use App\Livewire\Partners\Concerns\FiltersPartners;
use App\Models\FieldWorker;
use Illuminate\Database\Eloquent\Model;
use App\Services\AuthorizationService;
use App\Support\WorkerTypes;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The Worker Foundation's (Phase B0.1/B0.2) missing admin surface — mirrors Providers\Index exactly. Since 2026-10-26
 * it is the People tab of Admin → Partners (riders, drivers, helpers); /admin/workers redirects there.
 */
class Index extends Component
{
    use WithPagination;
    use HasRowArchive;
    use FiltersPartners;

    public string $statusFilter = '';

    protected $queryString = ['statusFilter', 'typeFilter', 'franchiseFilter', 'zoneFilter', 'cityFilter'];

    // --- Add parcel rider (CreateParcelRiderAction) ---
    public bool $showAddRider = false;
    public string $riderName = '';
    public string $riderPhone = '';
    public string $riderEmail = '';
    public string $riderPassword = '';
    public ?int $riderZoneId = null;
    public bool $riderVerified = false;
    public string $riderMessage = '';

    /** workers.view was seeded (2026_08_11_047000) but never checked -- see Commissions\Index's identical fix for the full reasoning. */
    public function mount(bool $embedded = false): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('workers.view'), 403, 'You do not have permission to view workers.');

        $this->embedded = $embedded;
        $this->startOnNeedsReview();
    }


    protected function archiveModel(): string
    {
        return FieldWorker::class;
    }

    protected function canArchiveRow(Model $row): bool
    {
        return auth()->user()->hasPermission('workers.review_kyc', array_filter([
            'zone_id' => $row->zone_id,
            'franchise_id' => $row->franchise_id,
        ]));
    }

    protected function archiveLabel(Model $row): string
    {
        return $row->user()->withTrashed()->value('name') ?? ('worker #'.$row->getKey());
    }

    protected function archiveWarning(Model $row): ?string
    {
        $open = 0;

        return $open > 0 ? "Heads-up: {$open} booking".($open === 1 ? ' is' : 's are').' still in progress with this worker.' : null;
    }

    /** workers.review_kyc at the chosen zone's own scope — the same permission that approves worker KYC. */
    public function addRider(\App\Actions\CreateParcelRiderAction $action): void
    {
        $this->riderMessage = '';
        $this->validate([
            'riderName' => ['required', 'string', 'max:120'],
            'riderPhone' => ['required', 'string', 'max:20'],
            'riderEmail' => ['nullable', 'email', 'max:190'],
            'riderPassword' => ['nullable', 'string', 'min:8', 'max:190'],
            'riderZoneId' => ['required', 'integer', 'exists:zones,id'],
            'riderVerified' => ['accepted'],
        ], ['riderVerified.accepted' => "Confirm you checked the rider's ID and driving licence in person."]);

        $zone = \App\Models\Zone::with('franchise')->findOrFail($this->riderZoneId);
        abort_unless(auth()->user()->hasPermission('workers.review_kyc', array_filter([
            'zone_id' => $zone->id, 'franchise_id' => $zone->franchise_id,
            'city_id' => $zone->franchise?->city_id, 'country_id' => $zone->franchise?->country_id,
        ])), 403);

        try {
            $worker = $action->execute(auth()->user(), $zone, trim($this->riderName), $this->riderPhone, trim($this->riderEmail) ?: null, $this->riderPassword ?: null);
        } catch (\RuntimeException $e) {
            $this->addError('riderPhone', $e->getMessage());

            return;
        }

        $this->reset(['riderName', 'riderPhone', 'riderEmail', 'riderPassword', 'riderZoneId', 'riderVerified', 'showAddRider']);
        $this->riderMessage = "Rider {$worker->user?->name} added. They sign in at ".route('rider.login').' with their mobile number and password.';
    }

    public function updatingStatusFilter()
    {
        $this->resetPage();
    }

    private function scopeColumns(): array
    {
        return ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
    }

    public function render()
    {
        $scoped = fn ($query) => app(AuthorizationService::class)->scopeQuery($query, auth()->user(), 'workers.view', $this->scopeColumns());

        $ofType = fn ($query) => $query->when($this->typeFilter !== '', fn ($q) => $q->whereHas('capabilities', fn ($c) => $c->where('capability_type', $this->typeFilter)));

        $workers = $this->applyOrder($this->applyStatus($this->applyGeography($ofType($scoped(FieldWorker::with(['user', 'zone', 'franchise.city', 'documents', 'capabilities']))))))
            ->paginate(20);

        $counts = $this->statusCounts($ofType($scoped(FieldWorker::query())));

        $canManage = auth()->user()->hasPermissionAnywhere('workers.review_kyc');
        $canForce = $this->isSuperAdminUser();
        $archiveBars = $this->archiveBars();

        $riderZones = $canManage
            ? app(AuthorizationService::class)->scopeQuery(\App\Models\Zone::query()->where('is_active', true), auth()->user(), 'workers.review_kyc', ['zone_id' => 'id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'])
                ->orderBy('name')->get(['id', 'name', 'franchise_id'])
            : collect();

        return view('livewire.workers.index', compact('workers', 'counts', 'canManage', 'canForce', 'archiveBars', 'riderZones') + [
            'statusTabs' => $this->statusTabs(),
            'typeOptions' => WorkerTypes::ALL,
        ] + $this->geographyOptions('workers.view'))
            ->layout('layouts.admin', ['title' => 'Workers']);
    }
}
