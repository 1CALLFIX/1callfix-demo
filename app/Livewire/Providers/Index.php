<?php

namespace App\Livewire\Providers;

use App\Models\Provider;
use App\Services\AuthorizationService;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $statusFilter = 'pending';
    public string $search = '';

    protected $queryString = ['statusFilter', 'search'];

    /** providers.view was seeded (2026_08_11_016000) but never checked -- see Commissions\Index's identical fix for the full reasoning. Not granted to the Operator role by design (its grant list stops at bookings.*), so Operator now correctly loses access here rather than before, when nothing enforced it. */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('providers.view'), 403, 'You do not have permission to view providers.');
    }

    public function updatingStatusFilter()
    {
        $this->resetPage();
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    private function scopeColumns(): array
    {
        return ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
    }

    public function render()
    {
        $scoped = fn ($query) => app(AuthorizationService::class)->scopeQuery($query, auth()->user(), 'providers.view', $this->scopeColumns());

        $providers = $scoped(Provider::with(['user', 'zone', 'documents']))
            ->when($this->statusFilter, fn ($q) => $q->where('kyc_status', $this->statusFilter))
            ->when($this->search, fn ($q) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$this->search}%")->orWhere('phone', 'like', "%{$this->search}%")))
            // Admin Polish + AI session, Part 1 item 3 — "should feel like a
            // queue to clear, not a spreadsheet". Pending applications are
            // now processed oldest-first (a real FIFO queue an operator
            // clears from the top down); Approved/Rejected stay newest-first
            // (browsing recent decisions is the natural order there).
            ->when($this->statusFilter === 'pending', fn ($q) => $q->oldest(), fn ($q) => $q->latest())
            ->paginate(20);

        $counts = $scoped(Provider::query())
            ->selectRaw('kyc_status, count(*) as total')
            ->groupBy('kyc_status')
            ->pluck('total', 'kyc_status');

        return view('livewire.providers.index', compact('providers', 'counts'))
            ->layout('layouts.admin', ['title' => 'Providers']);
    }
}
