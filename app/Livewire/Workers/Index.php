<?php

namespace App\Livewire\Workers;

use App\Livewire\Concerns\HasRowArchive;
use App\Models\FieldWorker;
use Illuminate\Database\Eloquent\Model;
use App\Services\AuthorizationService;
use Livewire\Component;
use Livewire\WithPagination;

/** The Worker Foundation's (Phase B0.1/B0.2) missing admin surface — mirrors Providers\Index exactly. */
class Index extends Component
{
    use WithPagination;
    use HasRowArchive;

    public string $statusFilter = '';

    protected $queryString = ['statusFilter'];

    /** workers.view was seeded (2026_08_11_047000) but never checked -- see Commissions\Index's identical fix for the full reasoning. */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('workers.view'), 403, 'You do not have permission to view workers.');
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

        $workers = $scoped(FieldWorker::with(['user', 'zone', 'documents', 'capabilities']))
            ->when($this->statusFilter === 'archived', fn ($q) => $q->onlyTrashed())
            ->when($this->statusFilter !== '' && $this->statusFilter !== 'archived', fn ($q) => $q->where('kyc_status', $this->statusFilter))
            ->latest()
            ->paginate(20);

        $counts = $scoped(FieldWorker::query())
            ->selectRaw('kyc_status, count(*) as total')
            ->groupBy('kyc_status')
            ->pluck('total', 'kyc_status');

        $counts = $counts->all();
        $counts[''] = array_sum($counts);

        $canManage = auth()->user()->hasPermissionAnywhere('workers.review_kyc');
        $canForce = $this->isSuperAdminUser();
        $archiveBars = $this->archiveBars();

        return view('livewire.workers.index', compact('workers', 'counts', 'canManage', 'canForce', 'archiveBars'))
            ->layout('layouts.admin', ['title' => 'Workers']);
    }
}
