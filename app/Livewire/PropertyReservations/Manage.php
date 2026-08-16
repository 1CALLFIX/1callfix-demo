<?php

namespace App\Livewire\PropertyReservations;

use App\Actions\AdminCancelPropertyReservationAction;
use App\Actions\CheckInPropertyReservationAction;
use App\Actions\CompletePropertyReservationAction;
use App\Actions\ConfirmPropertyReservationAction;
use App\Models\PropertyReservation;
use App\Services\AuthorizationService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Phase 22.7 (Property Rental) admin screen — oversight/intervention, not
 * primary order creation (unlike ParcelOrders\Manage/TaxiRides\Manage):
 * Property Rental is a genuine self-service browse-and-book domain (real
 * customer API exists — `PropertyReservationController`), so the admin's
 * real job here is viewing, confirming/checking-in/completing on a
 * customer's behalf when needed, and cancelling — not creating
 * reservations for a phone-in customer the way Bookings/Parcel/Taxi do.
 */
class Manage extends Component
{
    use WithPagination;

    public string $statusFilter = '';
    public string $search = '';
    public ?int $selectedReservationId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('property_reservations.view'), 403, 'You do not have permission to view property reservations.');
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
            ->scopeQuery(PropertyReservation::query(), auth()->user(), 'property_reservations.view', $this->scopeColumns())
            ->with(['customer', 'property.provider.user', 'franchise']);
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

    private function assertCanManage(): PropertyReservation
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('property_reservations.view'), 403);

        $reservation = $this->scopedReservationsQuery()->find($this->selectedReservationId);
        abort_if(! $reservation, 404);

        return $reservation;
    }

    public function confirmReservation(ConfirmPropertyReservationAction $action): void
    {
        $reservation = $this->assertCanManage();

        try {
            $action->execute($reservation->id);
            session()->flash('message', 'Reservation confirmed.');
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function checkInReservation(CheckInPropertyReservationAction $action): void
    {
        $reservation = $this->assertCanManage();

        try {
            $action->execute($reservation->id);
            session()->flash('message', 'Guest checked in.');
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function completeReservation(CompletePropertyReservationAction $action): void
    {
        $reservation = $this->assertCanManage();

        try {
            $action->execute($reservation->id);
            session()->flash('message', 'Stay marked complete.');
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function cancelReservation(AdminCancelPropertyReservationAction $action): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('property_reservations.cancel'), 403);
        $reservation = $this->assertCanManage();

        try {
            $action->execute($reservation->id, 'Cancelled by admin');
            session()->flash('message', 'Reservation cancelled.');
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function getSelectedReservationProperty(): ?PropertyReservation
    {
        if (! $this->selectedReservationId) {
            return null;
        }

        return $this->scopedReservationsQuery()->with('statusHistory')->find($this->selectedReservationId);
    }

    public function render()
    {
        if ($this->selectedReservation) {
            return view('livewire.property-reservations.manage', ['reservation' => $this->selectedReservation])->layout('layouts.admin', ['title' => 'Property Reservations']);
        }

        $reservations = $this->scopedReservationsQuery()
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($qq) => $qq
                ->where('code', 'like', "%{$this->search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$this->search}%"))))
            ->latest('id')
            ->paginate(20);

        return view('livewire.property-reservations.manage', ['reservation' => null, 'reservations' => $reservations])
            ->layout('layouts.admin', ['title' => 'Property Reservations']);
    }
}
