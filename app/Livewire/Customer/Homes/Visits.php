<?php

namespace App\Livewire\Customer\Homes;

use App\Models\HouseVisit;
use App\Services\Homes\HouseListingService;
use App\Support\SiteText;
use Livewire\Component;

/**
 * Houses for rent — My home visits (signed in): each visit asked for, with where it stands. Once the owner accepts:
 * the exact address, directions and the owner's number. Cancel while waiting or accepted.
 */
class Visits extends Component
{
    public string $error = '';

    public function cancel(int $id, HouseListingService $homes): void
    {
        $this->reset('error');
        $visit = HouseVisit::where('customer_id', auth()->id())->find($id) ?? abort(404); // outside the try: a 404 is a RuntimeException too
        try {
            $homes->cancel($visit, auth()->user());
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        $visits = HouseVisit::with(['listing.zone', 'listing.franchise.city', 'listing.provider.user'])
            ->where('customer_id', auth()->id())->latest('id')->limit(50)->get();

        return view('livewire.customer.homes.visits', ['visits' => $visits])
            ->layout('components.layouts.customer', ['title' => SiteText::get('homes.visits.title'), 'indexable' => false]);
    }
}
