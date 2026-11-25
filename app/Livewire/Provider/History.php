<?php

namespace App\Livewire\Provider;

use App\Livewire\Provider\Concerns\InteractsWithProvider;
use App\Models\Booking;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * PHASE PW1 §7.2 — this partner's past and present jobs. The exact shape
 * Customer\Orders\Index uses, scoped to `provider_id` instead of
 * `customer_id`. Read-only; each row links to the job screen (§6).
 */
class History extends Component
{
    use InteractsWithProvider;
    use WithPagination;

    /** '' = all; otherwise a status bucket. */
    public string $filter = '';

    private const ACTIVE = ['assigned', 'provider_en_route', 'in_progress', 'on_hold'];

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $provider = $this->provider();

        $query = Booking::where('provider_id', $provider->id)
            // franchise.country: TimezoneResolver shows each job's time in its own city's time zone, no N+1.
            ->with(['service:id,name', 'address:id,label', 'franchise.country'])
            ->latest('id');

        if ($this->filter === 'active') {
            $query->whereIn('status', self::ACTIVE);
        } elseif ($this->filter === 'completed') {
            $query->where('status', 'completed');
        } elseif ($this->filter === 'cancelled') {
            $query->where('status', 'cancelled');
        }

        return view('livewire.provider.history', [
            'bookings' => $query->paginate(15),
        ])->layout('components.layouts.provider', ['title' => 'Job history']);
    }
}
