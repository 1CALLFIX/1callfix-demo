<?php

namespace App\Livewire\Bookings;

use App\Actions\AdminCancelBookingAction;
use App\Actions\AdminReassignBookingAction;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Setting;
use Livewire\Component;

class Show extends Component
{
    public Booking $booking;
    public string $selectedProviderId = '';
    public string $cancelReason = '';
    public string $flashMessage = '';
    public string $flashType = 'success';

    public function mount(int $bookingId)
    {
        $this->booking = Booking::with([
            'customer', 'service', 'provider.user', 'address', 'zone', 'franchise',
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
