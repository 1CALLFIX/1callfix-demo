<?php

namespace App\Livewire\Customer\Parcel;

use App\Models\ParcelOrder;
use App\Models\Setting;
use App\Services\ModuleActivationService;
use App\Support\Modules;
use Livewire\Component;
use Livewire\WithPagination;

/** The customer's parcel orders, newest first. Always reachable for someone who has parcels. */
class Index extends Component
{
    use WithPagination;

    public function render()
    {
        return view('livewire.customer.parcel.index', [
            'orders' => ParcelOrder::where('customer_id', auth()->id())
                ->with(['dropoffAddress:id,address_line', 'franchise'])
                ->latest()
                ->paginate(10),
            'canSend' => app(ModuleActivationService::class)->isActiveAnywhere(Modules::PARCEL),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.customer', ['title' => 'My parcels']);
    }
}
