<?php

namespace App\Livewire\Bookings;

use App\Models\Booking;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $statusFilter = '';
    public string $search = '';

    protected $queryString = ['statusFilter', 'search'];

    public function updatingStatusFilter()
    {
        $this->resetPage();
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function render()
    {
        $bookings = Booking::with(['customer', 'service', 'provider.user'])
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search, fn ($q) => $q->where('code', 'like', "%{$this->search}%"))
            ->latest()
            ->paginate(20);

        $statusCounts = Booking::selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('livewire.bookings.index', compact('bookings', 'statusCounts'))
            ->layout('layouts.admin', ['title' => 'Bookings']);
    }
}
