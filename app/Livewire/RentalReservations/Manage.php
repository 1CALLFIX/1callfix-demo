<?php

namespace App\Livewire\RentalReservations;

use App\Actions\AdminCancelRentalReservationAction;
use App\Actions\CompleteRentalReservationAction;
use App\Actions\ConfirmRentalReservationAction;
use App\Actions\InspectRentalReservationAction;
use App\Actions\PickupRentalReservationAction;
use App\Actions\ReturnRentalReservationAction;
use App\Actions\StartRentalReservationAction;
use App\Models\RentalReservation;
use App\Services\AuthorizationService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * RENTAL MODULE IMPLEMENTATION admin screen -- the shared Vehicle/Equipment
 * engine counterpart to PropertyReservations\Manage. Same
 * oversight/intervention shape (list + detail + lifecycle actions), one
 * screen covering both rental_type values since they share one engine.
 */
class Manage extends Component
{
    use WithPagination;

    public string $statusFilter = '';
    public string $typeFilter = '';
    public string $search = '';
    public ?int $selectedReservationId = null;
    public string $inspectionNotes = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('rental_reservations.view'), 403, 'You do not have permission to view rental reservations.');
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingStatusFilter(): void { $this->resetPage(); }
    public function updatingTypeFilter(): void { $this->resetPage(); }

    private function scopeColumns(): array
    {
        return ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
    }

    private function scopedReservationsQuery()
    {
        return app(AuthorizationService::class)
            ->scopeQuery(RentalReservation::query(), auth()->user(), 'rental_reservations.view', $this->scopeColumns())
            ->with(['customer', 'rentable.provider.user', 'franchise', 'driver.user']);
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

    private function assertCanManage(): RentalReservation
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('rental_reservations.view'), 403);

        $reservation = $this->scopedReservationsQuery()->find($this->selectedReservationId);
        abort_if(! $reservation, 404);

        return $reservation;
    }

    public function confirmReservation(ConfirmRentalReservationAction $action): void
    {
        $reservation = $this->assertCanManage();

        try {
            $action->execute($reservation->id);
            session()->flash('message', 'Reservation confirmed.');
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function pickupReservation(PickupRentalReservationAction $action): void
    {
        $reservation = $this->assertCanManage();

        try {
            $action->execute($reservation->id);
            session()->flash('message', 'Pickup recorded.');
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function startReservation(StartRentalReservationAction $action): void
    {
        $reservation = $this->assertCanManage();

        try {
            $action->execute($reservation->id);
            session()->flash('message', 'Rental started.');
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function returnReservation(ReturnRentalReservationAction $action): void
    {
        $reservation = $this->assertCanManage();

        try {
            $action->execute($reservation->id);
            session()->flash('message', 'Return recorded.');
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function inspectReservation(InspectRentalReservationAction $action): void
    {
        $reservation = $this->assertCanManage();

        try {
            $action->execute($reservation->id, $this->inspectionNotes ?: null);
            $this->inspectionNotes = '';
            session()->flash('message', 'Inspection recorded.');
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function completeReservation(CompleteRentalReservationAction $action): void
    {
        $reservation = $this->assertCanManage();

        try {
            $action->execute($reservation->id);
            session()->flash('message', 'Rental completed.');
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function cancelReservation(AdminCancelRentalReservationAction $action): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('rental_reservations.cancel'), 403);
        $reservation = $this->assertCanManage();

        try {
            $action->execute($reservation->id, 'Cancelled by admin');
            session()->flash('message', 'Reservation cancelled.');
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function getSelectedReservationProperty(): ?RentalReservation
    {
        if (! $this->selectedReservationId) {
            return null;
        }

        return $this->scopedReservationsQuery()->with(['statusHistory', 'rentable.equipmentCategory'])->find($this->selectedReservationId);
    }

    public function render()
    {
        if ($this->selectedReservation) {
            return view('livewire.rental-reservations.manage', ['reservation' => $this->selectedReservation])->layout('layouts.admin', ['title' => 'Rental Reservations']);
        }

        $reservations = $this->scopedReservationsQuery()
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->typeFilter !== '', fn ($q) => $q->where('rental_type', $this->typeFilter))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($qq) => $qq
                ->where('code', 'like', "%{$this->search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$this->search}%"))))
            ->latest('id')
            ->paginate(20);

        return view('livewire.rental-reservations.manage', ['reservation' => null, 'reservations' => $reservations])
            ->layout('layouts.admin', ['title' => 'Rental Reservations']);
    }
}
