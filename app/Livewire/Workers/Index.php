<?php

namespace App\Livewire\Workers;

use App\Models\FieldWorker;
use Livewire\Component;
use Livewire\WithPagination;

/** The Worker Foundation's (Phase B0.1/B0.2) missing admin surface — mirrors Providers\Index exactly. */
class Index extends Component
{
    use WithPagination;

    public string $statusFilter = 'pending';

    protected $queryString = ['statusFilter'];

    /** workers.view was seeded (2026_08_11_047000) but never checked -- see Commissions\Index's identical fix for the full reasoning. */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('workers.view'), 403, 'You do not have permission to view workers.');
    }

    public function updatingStatusFilter()
    {
        $this->resetPage();
    }

    public function render()
    {
        $workers = FieldWorker::with(['user', 'zone', 'documents', 'capabilities'])
            ->when($this->statusFilter, fn ($q) => $q->where('kyc_status', $this->statusFilter))
            ->latest()
            ->paginate(20);

        $counts = FieldWorker::selectRaw('kyc_status, count(*) as total')
            ->groupBy('kyc_status')
            ->pluck('total', 'kyc_status');

        return view('livewire.workers.index', compact('workers', 'counts'))
            ->layout('layouts.admin', ['title' => 'Workers']);
    }
}
