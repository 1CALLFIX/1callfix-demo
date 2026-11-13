<?php

namespace App\Livewire\HallBookings;

use App\Models\HallBooking;
use App\Services\AuthorizationService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin → Rental → Hall bookings (Function halls R2): every hall booking in the admin's own area, newest first, with
 * its status, money and the time the owner must answer by. Read-only: the owner accepts or declines in the hall portal;
 * an unanswered booking is declined and refunded by itself.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'status')]
    public string $statusFilter = '';

    private const COLUMNS = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('halls.manage'), 403, 'You do not have permission to see hall bookings.');
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = app(AuthorizationService::class)->scopeQuery(HallBooking::query(), auth()->user(), 'halls.manage', self::COLUMNS);

        return view('livewire.hall-bookings.index', [
            'bookings' => (clone $query)->with(['hall', 'customer'])
                ->when(array_key_exists($this->statusFilter, HallBooking::STATUS_LABELS), fn ($q) => $q->where('status', $this->statusFilter))
                ->latest('id')->paginate(25),
            'tz' => app(\App\Services\TimezoneResolver::class)->platformTimezone(),
        ])->layout('layouts.admin', ['title' => 'Hall bookings']);
    }
}
