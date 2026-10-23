<?php

namespace App\Livewire\Rider;

use App\Livewire\Rider\Concerns\InteractsWithRider;
use App\Models\ParcelOrder;
use App\Models\Setting;
use Livewire\Component;
use Livewire\WithPagination;

/** The rider's finished parcels (delivered or cancelled after they took them), newest first. */
class History extends Component
{
    use InteractsWithRider;
    use WithPagination;

    public function render()
    {
        $rider = $this->rider();

        return view('livewire.rider.history', [
            'orders' => ParcelOrder::where('assigned_worker_id', $rider->id)
                ->whereIn('status', ['delivered', 'cancelled'])
                ->with(['dropoffAddress:id,address_line', 'commission', 'franchise'])
                ->latest('id')
                ->paginate(15),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.rider', ['title' => 'History']);
    }
}
