<?php

namespace App\Livewire\Seller;

use App\Livewire\Seller\Concerns\InteractsWithSeller;
use App\Models\Setting;
use App\Contracts\FirebaseTokenVerifier;
use App\Exceptions\FirebaseAuthException;
use App\Services\ActivityLogger;
use App\Services\PendingChangeService;
use App\Support\PhoneNumber;
use Livewire\Attributes\On;
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
    /** A new shop mobile number: '' → 'number' (typing) → 'code' (OTP sent). */
    public string $phoneStep = '';
    public string $newPhone = '';
    public string $phoneError = '';
    public string $addressLine = '';
    public ?float $lat = null;
    public ?float $lng = null;
    public string $minimumOrder = '';
    public string $deliveryFee = '';
    public string $freeDeliveryAbove = '';
    public $logo = null;
    /** E-commerce Phase 5: printed on the seller's tax invoices. */
    public string $gstin = '';
    public string $pan = '';

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
        $this->gstin = (string) $store->gstin;
        $this->pan = (string) $store->pan;
        // A change waiting for approval shows in the form, so saving other details never drops it.
        if ($pending = app(PendingChangeService::class)->pendingFor($store)) {
            foreach (['address_line' => 'addressLine', 'gstin' => 'gstin', 'pan' => 'pan'] as $field => $prop) {
                if (array_key_exists($field, $pending->new_values)) {
                    $this->{$prop} = (string) $pending->new_values[$field];
                }
            }
            if (array_key_exists('lat', $pending->new_values)) {
                $this->lat = $pending->new_values['lat'] !== null ? (float) $pending->new_values['lat'] : null;
                $this->lng = $pending->new_values['lng'] !== null ? (float) $pending->new_values['lng'] : null;
            }
        }
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
            'addressLine' => ['required', 'string', 'min:6', 'max:255'],
            'minimumOrder' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'deliveryFee' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'freeDeliveryAbove' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'gstin' => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'pan' => ['nullable', 'string', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
        ], ['gstin.regex' => 'Enter the 15-character GSTIN in capitals.', 'pan.regex' => 'Enter the 10-character PAN in capitals.'], ['addressLine' => 'address', 'minimumOrder' => 'minimum order', 'deliveryFee' => 'delivery fee', 'freeDeliveryAbove' => 'free delivery above']);

        // A pin moved outside every zone would send riders to the wrong place; keep the shop where it was.
        if ($this->lat !== null && $this->lng !== null
            && ((float) $this->lat !== (float) $store->lat || (float) $this->lng !== (float) $store->lng)
            && app(CustomerLocationContext::class)->nearestCoveringZone((float) $this->lat, (float) $this->lng) === null) {
            $this->flash('That pin is outside our delivery areas. Contact support to move your shop.', 'error');

            return;
        }

        $fields = [
            'description' => trim($this->description) ?: null,
            'address_line' => trim($this->addressLine),
            'lat' => $this->lat ?? $store->lat,
            'lng' => $this->lng ?? $store->lng,
            'minimum_order_amount' => round((float) ($this->minimumOrder ?: 0), 2),
            'delivery_fee_flat' => round((float) ($this->deliveryFee ?: 0), 2),
            'free_delivery_above_amount' => $this->freeDeliveryAbove === '' ? null : round((float) $this->freeDeliveryAbove, 2),
            'gstin' => strtoupper(trim($this->gstin)) ?: null,
            'pan' => strtoupper(trim($this->pan)) ?: null,
        ];
        if ($this->logo) {
            if ($store->logo && ! str_contains($store->logo, '://')) {
                Storage::disk('public')->delete($store->logo);
            }
            $fields['logo'] = $this->logo->store('stores/'.$store->id, 'public');
            $this->logo = null;
        }

        // Owner rule 2026-11-03 (PendingChangeService): on an approved shop the address, map pin, GSTIN and PAN wait
        // for admin approval (riders and tax invoices depend on them); the shop keeps its old ones until then. Logo,
        // description and order terms change at once. The mobile number changes only after an OTP (below).
        $changes = app(PendingChangeService::class);
        $review = $store->approval_status === 'approved';
        [$now, $wait] = $review ? $changes->split($store, $fields) : [$fields, []];
        $store->update($now);
        ActivityLogger::logModel(auth()->user(), $store, 'Seller updated shop details', ['fields' => array_keys($store->getChanges())]);
        if ($wait !== []) {
            $pending = $changes->request($store, $wait, auth()->user(), $store->id);
            $this->flash('Saved. Your new '.mb_strtolower($pending->summary()).' will be used once 1CallFix approves it; until then the old details stay.', 'success');
        } else {
            if ($review) {
                $changes->withdraw($store, auth()->user());
            }
            $this->flash('Shop details saved.', 'success');
        }
        $this->dispatch('seller-scroll-top');
    }

    /** Step 1 of a new shop mobile number: an OTP goes to the NEW number (Firebase, as at sign-up). */
    public function sendPhoneCode(): void
    {
        $this->phoneError = '';
        $this->validate(['newPhone' => ['required', 'string', 'max:20']], [], ['newPhone' => 'mobile number']);
        if (! PhoneNumber::looksValid($this->newPhone)) {
            $this->addError('newPhone', 'Enter a 10-digit mobile number.');

            return;
        }
        if (PhoneNumber::national($this->newPhone) === PhoneNumber::national((string) $this->shop()->phone)) {
            $this->addError('newPhone', 'That is already the shop number.');

            return;
        }
        $this->phoneStep = 'code';
        $this->dispatch('firebase-send-phone-otp', phone: PhoneNumber::e164($this->newPhone));
    }

    #[On('firebase-error')]
    public function firebaseError(string $message): void
    {
        $this->phoneError = $message;
    }

    /** Step 2: the code proved the seller holds the new number; only then does the shop number change. */
    #[On('firebase-phone-token')]
    public function phoneTokenReceived(string $idToken, FirebaseTokenVerifier $firebase): void
    {
        $this->phoneError = '';
        if ($this->phoneStep !== 'code' || $this->newPhone === '') {
            return;
        }
        try {
            $identity = $firebase->verify($idToken);
        } catch (FirebaseAuthException $e) {
            report($e);
            $this->phoneError = 'Could not verify that code. Request a new one.';

            return;
        }
        if (! $identity->isPhoneProvider() || PhoneNumber::national($this->newPhone) !== PhoneNumber::national((string) $identity->phoneNumber)) {
            $this->phoneError = 'That verification does not match the new number.';

            return;
        }
        $store = $this->shop();
        $old = $store->phone;
        $store->update(['phone' => PhoneNumber::national($this->newPhone)]);
        ActivityLogger::logModel(auth()->user(), $store, 'Seller changed the shop mobile number (OTP verified)', ['from' => $old, 'to' => $store->phone]);
        $this->phone = (string) $store->phone;
        $this->reset('newPhone', 'phoneStep', 'phoneError');
        $this->flash('Shop mobile number changed to '.$store->phone.'.', 'success');
        $this->dispatch('seller-scroll-top');
    }

    public function cancelPhoneChange(): void
    {
        $this->reset('newPhone', 'phoneStep', 'phoneError');
    }

    private function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }

    public function render()
    {
        $store = $this->shop();
        $changes = \App\Models\PendingChange::where('changeable_type', $store->getMorphClass())->where('changeable_id', $store->id)
            ->where('status', '!=', \App\Models\PendingChange::REPLACED)->latest('id')->first();

        return view('livewire.seller.shop', [
            'store' => $store,
            'latestChange' => $changes,
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.seller', ['title' => 'Shop']);
    }
}
