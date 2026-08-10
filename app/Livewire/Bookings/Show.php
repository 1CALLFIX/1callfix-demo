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

    public function reassign(AdminReassignBookingAction $action)
    {
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
