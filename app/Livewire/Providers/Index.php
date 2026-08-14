<?php

namespace App\Livewire\Providers;

use App\Models\Provider;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $statusFilter = 'pending';

    protected $queryString = ['statusFilter'];

    /** providers.view was seeded (2026_08_11_016000) but never checked -- see Commissions\Index's identical fix for the full reasoning. Not granted to the Operator role by design (its grant list stops at bookings.*), so Operator now correctly loses access here rather than before, when nothing enforced it. */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('providers.view'), 403, 'You do not have permission to view providers.');
    }

    public function updatingStatusFilter()
    {
        $this->resetPage();
    }

    public function render()
    {
        $providers = Provider::with(['user', 'zone', 'documents'])
            ->when($this->statusFilter, fn ($q) => $q->where('kyc_status', $this->statusFilter))
            ->latest()
            ->paginate(20);

        $counts = Provider::selectRaw('kyc_status, count(*) as total')
            ->groupBy('kyc_status')
            ->pluck('total', 'kyc_status');

        return view('livewire.providers.index', compact('providers', 'counts'))
            ->layout('layouts.admin', ['title' => 'Providers']);
    }
}
