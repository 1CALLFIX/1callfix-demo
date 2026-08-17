<?php

namespace App\Livewire\HotelReservations;

use App\Actions\AdminCancelHotelReservationAction;
use App\Actions\CheckInHotelReservationAction;
use App\Actions\CheckOutHotelReservationAction;
use App\Actions\CompleteHotelReservationAction;
use App\Actions\ConfirmHotelReservationAction;
use App\Models\HotelReservation;
use App\Services\AuthorizationService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * HOTEL / STAY BOOKING MODULE admin screen — oversight/intervention, not
 * primary order creation, same reasoning `PropertyReservations\Manage`'s
 * own docblock gives (a genuine self-service browse-and-book domain — real
 * customer API exists). Extended with the extra Check-Out step this
 * vertical's own distinct lifecycle has.
 */
class Manage extends Component
{
    use WithPagination;

    public string $statusFilter = '';
    public string $search = '';
    public ?int $selectedReservationId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('hotel_reservations.view'), 403, 'You do not have permission to view hotel reservations.');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    private function scopeColumns(): array
    {
        return ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
    }

    private function scopedReservationsQuery()
    {
        return app(AuthorizationService::class)
            ->scopeQuery(HotelReservation::query(), auth()->user(), 'hotel_reservations.view', $this->scopeColumns())
            ->with(['customer', 'accommodation.provider.user', 'franchise']);
    }

    public function viewReservation(int $reservationId): void
    {
        $reservation = $this->scopedReservationsQuery()->find($reservationId);
        abort_if(! $reservation, 404, 'Reservation not found, or you do not have access to it.');

        $this->selectedReservationId = $reservationId;
    }

    public function backToList(): void
    {
        $this->selectedReservationId = null;
    }

    private function assertCanManage(): HotelReservation
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('hotel_reservations.view'), 403);

        $reservation = $this->scopedReservationsQuery()->find($this->selectedReservationId);
        abort_if(! $reservation, 404);

        return $reservation;
    }

    public function confirmReservation(ConfirmHotelReservationAction $action): void
    {
        $reservation = $this->assertCanManage();

        try {
            $action->execute($reservation->id);
            session()->flash('message', 'Reservation confirmed.');
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function checkInReservation(CheckInHotelReservationAction $action): void
    {
        $reservation = $this->assertCanManage();

        try {
            $action->execute($reservation->id);
            session()->flash('message', 'Guest checked in.');
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function checkOutReservation(CheckOutHotelReservationAction $action): void
    {
        $reservation = $this->assertCanManage();

        try {
            $action->execute($reservation->id);
            session()->flash('message', 'Guest checked out.');
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function completeReservation(CompleteHotelReservationAction $action): void
    {
        $reservation = $this->assertCanManage();

        try {
            $action->execute($reservation->id);
            session()->flash('message', 'Reservation marked complete.');
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function cancelReservation(AdminCancelHotelReservationAction $action): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('hotel_reservations.cancel'), 403);
        $reservation = $this->assertCanManage();

        try {
            $action->execute($reservation->id, 'Cancelled by admin');
            session()->flash('message', 'Reservation cancelled.');
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function getSelectedReservationProperty(): ?HotelReservation
    {
        if (! $this->selectedReservationId) {
            return null;
        }

        return $this->scopedReservationsQuery()->with(['statusHistory', 'rooms.roomType', 'rooms.ratePlan', 'guests'])->find($this->selectedReservationId);
    }

    public function render()
    {
        if ($this->selectedReservation) {
            return view('livewire.hotel-reservations.manage', ['reservation' => $this->selectedReservation])->layout('layouts.admin', ['title' => 'Hotel Reservations']);
        }

        $reservations = $this->scopedReservationsQuery()
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($qq) => $qq
                ->where('code', 'like', "%{$this->search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$this->search}%"))))
            ->latest('id')
            ->paginate(20);

        return view('livewire.hotel-reservations.manage', ['reservation' => null, 'reservations' => $reservations])
            ->layout('layouts.admin', ['title' => 'Hotel Reservations']);
    }
}
