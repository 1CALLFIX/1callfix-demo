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
