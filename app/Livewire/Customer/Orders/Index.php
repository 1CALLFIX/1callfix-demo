<?php

namespace App\Livewire\Customer\Orders;

use App\Models\Booking;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Phase E6 — the customer's own booking history. The exact query
 * App\Http\Controllers\API\BookingController::mine() runs (scoped hard to
 * customer_id = the authed user, newest first), rendered as a list instead
 * of paginated JSON. No status is computed here — each row shows the
 * booking's real `status` string straight from the FSM.
 */
class Index extends Component
{
    use WithPagination;

    /** '' = all; otherwise one of the real FSM status buckets. */
    public string $filter = '';

    private const ACTIVE = ['pending', 'searching_provider', 'assigned', 'provider_en_route', 'in_progress'];

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = Booking::where('customer_id', auth()->id())
            ->with(['service:id,name,category_id', 'service.category:id,name', 'address:id,label', 'provider.user:id,name', 'franchise:id,country_id', 'franchise.country:id,default_timezone'])
            ->latest();

        if ($this->filter === 'active') {
            $query->whereIn('status', self::ACTIVE);
        } elseif ($this->filter === 'completed') {
            $query->where('status', 'completed');
        } elseif ($this->filter === 'cancelled') {
            $query->where('status', 'cancelled');
        }

        return view('livewire.customer.orders.index', [
            'bookings' => $query->paginate(10),
        ])->layout('components.layouts.customer', ['title' => 'My bookings']);
    }
}
