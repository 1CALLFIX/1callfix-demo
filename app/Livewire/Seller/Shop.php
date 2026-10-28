<?php

namespace App\Livewire\Seller;

use App\Livewire\Seller\Concerns\InteractsWithSeller;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\Customer\CustomerLocationContext;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Seller → Shop: what customers see about the shop (description, logo, phone, address and map pin) and its order
 * terms (minimum order, delivery fee, free delivery above). The name, slug, tax rate, approval and on/off switch are
 * the admin's (Admin → Stores). Free delivery is a benefit: cash orders always pay the delivery fee (thumb rule).
 */
class Shop extends Component
{
    use InteractsWithSeller;
    use WithFileUploads;

    public string $description = '';
    public string $phone = '';
    public string $addressLine = '';
    public ?float $lat = null;
    public ?float $lng = null;
    public string $minimumOrder = '';
    public string $deliveryFee = '';
    public string $freeDeliveryAbove = '';
    public $logo = null;

    public string $flashMessage = '';
    public string $flashType = 'success';

    public function mount(): void
    {
        $store = $this->shop();
        $this->description = (string) $store->description;
        $this->phone = (string) $store->phone;
        $this->addressLine = (string) $store->address_line;
        $this->lat = $store->lat !== null ? (float) $store->lat : null;
        $this->lng = $store->lng !== null ? (float) $store->lng : null;
        $this->minimumOrder = (string) (float) $store->minimum_order_amount;
        $this->deliveryFee = (string) (float) $store->delivery_fee_flat;
        $this->freeDeliveryAbove = $store->free_delivery_above_amount !== null ? (string) (float) $store->free_delivery_above_amount : '';
    }

    /** The shared [data-locate-address] button (resources/js/geolocation.js) posts the pin here. */
    public function useCurrentLocationForNewAddress(float $lat, float $lng): void
    {
        validator(['lat' => $lat, 'lng' => $lng], ['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']])->validate();
        $this->lat = $lat;
        $this->lng = $lng;
        $this->flash('Pin set to your current location. Save to keep it.', 'success');
    }

    public function save(): void
    {
        $store = $this->shop();
        $this->validate([
            'description' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+][0-9 \-]*$/'],
            'addressLine' => ['required', 'string', 'min:6', 'max:255'],
            'minimumOrder' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'deliveryFee' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'freeDeliveryAbove' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [], ['addressLine' => 'address', 'minimumOrder' => 'minimum order', 'deliveryFee' => 'delivery fee', 'freeDeliveryAbove' => 'free delivery above']);

        // A pin moved outside every zone would send riders to the wrong place; keep the shop where it was.
        if ($this->lat !== null && $this->lng !== null
            && ((float) $this->lat !== (float) $store->lat || (float) $this->lng !== (float) $store->lng)
            && app(CustomerLocationContext::class)->nearestCoveringZone((float) $this->lat, (float) $this->lng) === null) {
            $this->flash('That pin is outside our delivery areas. Contact support to move your shop.', 'error');

            return;
        }

        $fields = [
            'description' => trim($this->description) ?: null,
            'phone' => trim($this->phone) ?: null,
            'address_line' => trim($this->addressLine),
            'lat' => $this->lat ?? $store->lat,
            'lng' => $this->lng ?? $store->lng,
            'minimum_order_amount' => round((float) ($this->minimumOrder ?: 0), 2),
            'delivery_fee_flat' => round((float) ($this->deliveryFee ?: 0), 2),
            'free_delivery_above_amount' => $this->freeDeliveryAbove === '' ? null : round((float) $this->freeDeliveryAbove, 2),
        ];
        if ($this->logo) {
            if ($store->logo && ! str_contains($store->logo, '://')) {
                Storage::disk('public')->delete($store->logo);
            }
            $fields['logo'] = $this->logo->store('stores/'.$store->id, 'public');
            $this->logo = null;
        }

        $store->update($fields);
        ActivityLogger::logModel(auth()->user(), $store, 'Seller updated shop details', ['fields' => array_keys($store->getChanges())]);
        $this->flash('Shop details saved.', 'success');
    }

    private function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }

    public function render()
    {
        return view('livewire.seller.shop', [
            'store' => $this->shop(),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.seller', ['title' => 'Shop']);
    }
}
