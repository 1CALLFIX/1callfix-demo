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
use App\Models\Store;
use App\Services\Customer\CustomerLocationContext;
use App\Support\FoodSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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

    /** Food F1 (restaurants only). */
    public string $fssai = '';
    public array $cuisines = [];
    public string $costForTwo = '';
    public string $packagingCharge = '';
    public string $prepMinutes = '';
    /** day (0 = Sunday) => list of ['opens' => 'HH:MM', 'closes' => 'HH:MM']; an empty day is closed. */
    public array $hours = [];

    private const MAX_CUISINES = 3;
    private const MAX_SLOTS_PER_DAY = 3;

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
        if ($store->isRestaurant()) {
            $this->fssai = (string) $store->fssai_number;
            $this->cuisines = $store->cuisineList();
            $this->costForTwo = $store->cost_for_two !== null ? (string) (float) $store->cost_for_two : '';
            $this->packagingCharge = (float) $store->packaging_charge > 0 ? (string) (float) $store->packaging_charge : '';
            $this->prepMinutes = $store->prep_minutes ? (string) $store->prep_minutes : '';
            $this->hours = array_fill(0, 7, []);
            foreach ($store->hours as $slot) {
                $this->hours[$slot->day][] = ['opens' => substr($slot->opens_at, 0, 5), 'closes' => substr($slot->closes_at, 0, 5)];
            }
        }

        // A change waiting for approval shows in the form, so saving other details never drops it.
        if ($pending = app(PendingChangeService::class)->pendingFor($store)) {
            foreach (['address_line' => 'addressLine', 'gstin' => 'gstin', 'pan' => 'pan', 'fssai_number' => 'fssai'] as $field => $prop) {
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

    public function addSlot(int $day): void
    {
        if ($day >= 0 && $day <= 6 && count($this->hours[$day] ?? []) < self::MAX_SLOTS_PER_DAY) {
            $this->hours[$day][] = ['opens' => '11:00', 'closes' => '22:00'];
        }
    }

    public function removeSlot(int $day, int $index): void
    {
        unset($this->hours[$day][$index]);
        $this->hours[$day] = array_values($this->hours[$day] ?? []);
    }

    /** "Same every day": Monday's slots copied to all seven days. */
    public function copyMondayToAll(): void
    {
        foreach (range(0, 6) as $day) {
            $this->hours[$day] = $this->hours[1] ?? [];
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

        if ($store->isRestaurant()) {
            $this->validateRestaurant($store);
        }

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

        if ($store->isRestaurant()) {
            $fields += [
                'fssai_number' => preg_replace('/\D/', '', $this->fssai) ?: null,
                'cuisines' => $this->cuisines ? implode(', ', $this->cuisines) : null,
                'cost_for_two' => $this->costForTwo === '' ? null : round((float) $this->costForTwo, 2),
                'packaging_charge' => $this->packagingCharge === '' ? 0 : round((float) $this->packagingCharge, 2),
                'prep_minutes' => $this->prepMinutes === '' ? null : (int) $this->prepMinutes,
            ];
        }

        // Owner rule 2026-11-03 (PendingChangeService): on an approved shop the address, map pin, GSTIN, PAN and FSSAI
        // number wait for admin approval (riders, tax invoices and the food licence depend on them); the shop keeps its
        // old ones until then. Logo, description, order terms, cuisines, charges and hours change at once. The mobile
        // number changes only after an OTP (below).
        $changes = app(PendingChangeService::class);
        $review = $store->approval_status === 'approved';
        [$now, $wait] = $review ? $changes->split($store, $fields) : [$fields, []];
        $hoursChanged = false;
        DB::transaction(function () use ($store, $now, &$hoursChanged) {
            $store->update($now);
            if ($store->isRestaurant()) {
                $hoursChanged = $this->saveHours($store);
            }
        });
        ActivityLogger::logModel(auth()->user(), $store, 'Seller updated shop details', array_filter(['fields' => array_keys($store->getChanges()), 'hours_changed' => $hoursChanged ?: null]));
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

    /** Restaurant fields (Food F1); a problem throws the usual validation errors onto the form. */
    private function validateRestaurant(Store $store): void
    {
        $packagingMax = FoodSettings::packagingChargeMax($store->settingsScope());
        $this->fssai = trim($this->fssai);
        $this->hours = array_replace(array_fill(0, 7, []), array_map(fn ($slots) => array_values((array) $slots), $this->hours));
        $this->validate([
            'fssai' => ['nullable', 'regex:/^\d{14}$/'],
            'cuisines' => ['array', 'max:'.self::MAX_CUISINES],
            'cuisines.*' => ['string', Rule::in(FoodSettings::cuisines())],
            'costForTwo' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'packagingCharge' => ['nullable', 'numeric', 'min:0', 'max:'.($packagingMax ?? 0)],
            'prepMinutes' => ['nullable', 'integer', 'min:5', 'max:120'],
            'hours' => ['array', 'size:7'],
            'hours.*' => ['array', 'max:'.self::MAX_SLOTS_PER_DAY],
            'hours.*.*.opens' => ['required', 'date_format:H:i'],
            'hours.*.*.closes' => ['required', 'date_format:H:i', 'different:hours.*.*.opens'],
        ], [
            'fssai.regex' => 'The FSSAI licence number is 14 digits.',
            'cuisines.max' => 'Choose up to '.self::MAX_CUISINES.' cuisines.',
            'packagingCharge.max' => $packagingMax === null ? 'Packaging charges are not allowed.' : 'At most '.$packagingMax.'.',
            'hours.*.*.closes.different' => 'Opening and closing time cannot be the same.',
        ], ['costForTwo' => 'cost for two', 'packagingCharge' => 'packaging charge', 'prepMinutes' => 'cooking time']);
    }

    /** Replace the restaurant's opening hours with the form's. Returns true when they changed. */
    private function saveHours(Store $store): bool
    {
        $before = $store->hours()->get()->map(fn ($h) => $h->day.'|'.substr($h->opens_at, 0, 5).'|'.substr($h->closes_at, 0, 5))->sort()->values()->all();
        $after = [];
        foreach ($this->hours as $day => $slots) {
            foreach ($slots as $slot) {
                $after[] = ((int) $day).'|'.$slot['opens'].'|'.$slot['closes'];
            }
        }
        sort($after);
        if ($before === $after) {
            return false;
        }
        $store->hours()->delete();
        foreach ($after as $row) {
            [$day, $opens, $closes] = explode('|', $row);
            $store->hours()->create(['day' => (int) $day, 'opens_at' => $opens.':00', 'closes_at' => $closes.':00']);
        }

        return true;
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
            'cuisineOptions' => FoodSettings::cuisines(),
            'packagingMax' => FoodSettings::packagingChargeMax($this->shop()->settingsScope()),
            'defaultPrep' => (int) FoodSettings::get('food.default_prep_minutes', $this->shop()->settingsScope()),
            'dayNames' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        ])->layout('components.layouts.seller', ['title' => $this->shop()->isRestaurant() ? 'Restaurant' : 'Shop']);
    }
}
