<?php

namespace App\Livewire\Customer\Vehicles;

use App\Models\VehicleContactView;
use App\Models\VehicleListing;
use App\Services\Vehicles\VehicleListingService;
use App\Support\PhoneNumber;
use App\Support\VehicleListingSettings;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Vehicles for rent — the vehicle page's contact panel, loaded on its own request (the page itself is cached): the price
 * and one big "Show owner's number" button. Once shown: Call and WhatsApp buttons. No booking or payment runs here
 * (OLX-style); the customer and the owner agree between them. Signed out: one button to sign in and come back.
 */
#[Lazy]
class Contact extends Component
{
    #[Locked]
    public int $listingId = 0;

    public ?string $ownerPhone = null;
    public string $error = '';

    public function mount(int $listingId): void
    {
        $this->listingId = $listingId;
        // A number this customer already saw stays visible (no extra tap, no extra count).
        if (($user = auth()->user()) && VehicleContactView::where('customer_id', $user->id)->where('vehicle_listing_id', $listingId)->exists()) {
            $vehicle = VehicleListing::with('provider.user')->find($listingId);
            if ($vehicle && VehicleListingService::visible($vehicle) && VehicleListingSettings::on('vehicles.show_owner_phone', $vehicle->scope())) {
                $this->ownerPhone = (string) ($vehicle->contact_phone ?: $vehicle->provider?->user?->phone) ?: null;
            }
        }
    }

    private function listing(): VehicleListing
    {
        $vehicle = VehicleListing::with(['franchise.city', 'provider.user'])->find($this->listingId);
        abort_unless($vehicle && VehicleListingService::visible($vehicle), 404);

        return $vehicle;
    }

    public function placeholder()
    {
        return '<div class="h-48 animate-pulse rounded-2xl bg-slate-100" aria-hidden="true"></div>';
    }

    public function showPhone(VehicleListingService $vehicles): void
    {
        $this->reset('error');
        abort_unless(auth()->check(), 403);
        $vehicle = $this->listing();
        try {
            $this->ownerPhone = $vehicles->revealPhone(auth()->user(), $vehicle);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        $vehicle = $this->listing();
        $user = auth()->user();
        $symbol = \App\Models\Setting::get('locale.currency_symbol', '₹');
        $digits = $this->ownerPhone ? preg_replace('/\D/', '', PhoneNumber::e164($this->ownerPhone)) : null;

        return view('livewire.customer.vehicles.contact', [
            'vehicle' => $vehicle,
            'price' => $symbol.number_format((float) $vehicle->price_day),
            'hour' => $vehicle->price_hour ? $symbol.number_format((float) $vehicle->price_hour) : null,
            'deposit' => $vehicle->deposit ? $symbol.number_format((float) $vehicle->deposit) : null,
            'phoneAllowed' => VehicleListingSettings::on('vehicles.show_owner_phone', $vehicle->scope()),
            'own' => $user && $vehicle->provider?->user_id === $user->id,
            'whatsapp' => $digits ? 'https://wa.me/'.$digits.'?text='.rawurlencode(\App\Support\SiteText::get('vehicles.contact.whatsapp_text', ['title' => $vehicle->title])) : null,
            'signInUrl' => route('customer.login', ['intended' => '/'.ltrim(parse_url(route('customer.vehicles.show', [(string) $vehicle->franchise?->city?->slug, $vehicle->slug]), PHP_URL_PATH), '/')]),
        ]);
    }
}
