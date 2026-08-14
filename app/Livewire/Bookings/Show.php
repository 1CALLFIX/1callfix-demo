<?php

namespace App\Livewire\Bookings;

use App\Actions\AdminCancelBookingAction;
use App\Actions\AdminReassignBookingAction;
use App\Actions\AssignBookingToWorkerAction;
use App\Models\Booking;
use App\Models\PartnerWorker;
use App\Models\Provider;
use App\Models\Setting;
use Livewire\Component;

class Show extends Component
{
    public Booking $booking;
    public string $selectedProviderId = '';
    public string $cancelReason = '';
    public string $selectedWorkerId = '';
    public string $flashMessage = '';
    public string $flashType = 'success';

    /** bookings.view was seeded (2026_08_11_016000) but never checked on this detail screen (only the reassign/cancel actions were gated) -- see Commissions\Index's identical fix for the full reasoning. */
    public function mount(int $bookingId)
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('bookings.view'), 403, 'You do not have permission to view bookings.');

        $this->booking = Booking::with([
            'customer', 'service', 'provider.user', 'assignedWorker.user', 'address', 'zone', 'franchise',
            'dispatchAttempts.provider.user', 'extraItems', 'payment', 'commission',
            'statusHistory' => fn ($q) => $q->orderBy('changed_at'),
        ])->findOrFail($bookingId);
    }

    public function getAvailableProvidersProperty()
    {
        return Provider::with('user')
            ->where('zone_id', $this->booking->zone_id)
            ->where('is_active', true)
            ->get();
    }

    /**
     * Workers on the booking's own accepting Provider's team — the same
     * "active partner_workers link" eligibility AssignBookingToWorkerAction
     * itself enforces server-side; this is just a reasonable candidate
     * list for the dropdown, not the final authority (the Action re-checks
     * everything, including the capability match this list doesn't
     * pre-filter on, on submit).
     */
    public function getAvailableWorkersProperty()
    {
        if (! $this->booking->provider_id) {
            return collect();
        }

        return PartnerWorker::with('fieldWorker.user')
            ->where('provider_id', $this->booking->provider_id)
            ->where('status', 'active')
            ->get()
            ->pluck('fieldWorker')
            ->filter();
    }

    public function canAssignWorker(): bool
    {
        return (bool) $this->booking->provider_id
            && in_array($this->booking->status, ['assigned', 'provider_en_route'], true);
    }

    public function getCurrencySymbolProperty(): string
    {
        return Setting::get('locale.currency_symbol', '₹');
    }

    /**
     * The booking's own position in the geography cascade — matches the
     * `{level}_id` shape both Setting::get() and AuthorizationService::can()
     * already expect, so a Zone/City/Country/Franchise Admin's role
     * assignment against this booking's franchise resolves correctly.
     */
    private function bookingScope(): array
    {
        $this->booking->loadMissing('franchise');

        return array_filter([
            'zone_id' => $this->booking->zone_id,
            'franchise_id' => $this->booking->franchise_id,
            'city_id' => $this->booking->franchise?->city_id,
            'country_id' => $this->booking->franchise?->country_id,
        ]);
    }

    public function reassign(AdminReassignBookingAction $action)
    {
        if (! auth()->user()->hasPermission('bookings.reassign', $this->bookingScope())) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to reassign this booking.';
            return;
        }

        if (!$this->selectedProviderId) {
            $this->flashType = 'error';
            $this->flashMessage = 'Select a provider first.';
            return;
        }

        try {
            $this->booking = $action->execute($this->booking->id, (int) $this->selectedProviderId);
            $this->booking->load(['provider.user', 'statusHistory']);
            $this->flashType = 'success';
            $this->flashMessage = 'Booking reassigned successfully.';
            $this->selectedProviderId = '';
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    /**
     * Admin-initiated worker delegation — the same AssignBookingToWorkerAction
     * (unmodified) the Partner-facing API already uses (Phase B0.2). Reuses
     * bookings.reassign rather than a new permission: "who is doing this
     * job" is the same admin capability whether it's a different Provider
     * or a Worker on the current one's team.
     */
    public function assignWorker(AssignBookingToWorkerAction $action)
    {
        if (! auth()->user()->hasPermission('bookings.reassign', $this->bookingScope())) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to assign a worker to this booking.';
            return;
        }

        if (! $this->selectedWorkerId) {
            $this->flashType = 'error';
            $this->flashMessage = 'Select a worker first.';
            return;
        }

        try {
            $this->booking = $action->execute($this->booking->id, $this->booking->provider, (int) $this->selectedWorkerId);
            $this->booking->load(['assignedWorker.user', 'statusHistory']);
            $this->flashType = 'success';
            $this->flashMessage = 'Worker assigned.';
            $this->selectedWorkerId = '';
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    public function cancel(AdminCancelBookingAction $action)
    {
        if (! auth()->user()->hasPermission('bookings.cancel', $this->bookingScope())) {
            $this->flashType = 'error';
            $this->flashMessage = 'You do not have permission to cancel this booking.';
            return;
        }

        if (!$this->cancelReason) {
            $this->flashType = 'error';
            $this->flashMessage = 'Enter a cancellation reason.';
            return;
        }

        try {
            $this->booking = $action->execute($this->booking->id, $this->cancelReason);
            $this->booking->load('statusHistory');
            $this->flashType = 'success';
            $this->flashMessage = 'Booking cancelled.';
            $this->cancelReason = '';
        } catch (\Throwable $e) {
            $this->flashType = 'error';
            $this->flashMessage = $e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.bookings.show')
            ->layout('layouts.admin', ['title' => "Booking {$this->booking->code}"]);
    }
}
