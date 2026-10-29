<?php

namespace App\Livewire\Customer\Parcel;

use App\Actions\CreateParcelOrderAction;
use App\Exceptions\ModuleNotActiveException;
use App\Exceptions\ParcelQuoteException;
use App\Models\Address;
use App\Models\ParcelOrder;
use App\Models\Setting;
use App\Models\Zone;
use App\Services\Customer\CustomerLocationContext;
use App\Services\ModuleActivationService;
use App\Services\Parcel\ParcelPaymentService;
use App\Services\Parcel\ParcelPricing;
use App\Services\Parcel\ParcelSettings;
use App\Services\TimezoneResolver;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Customer web "Send a parcel" (owner decision 2026-10-23). Pickup is a saved address or a pin (map search /
 * current location); the drop point is any pin plus the recipient's name and phone. Price is a live
 * ParcelPricing quote from the two pins — the same calculation CreateParcelOrderAction charges. Pins typed
 * here are saved as parcel-only addresses (Address::is_parcel_only), so they never clutter the address book.
 *
 * Where it is offered is decided by the Modules screen alone: the pickup pin's zone must have parcel active
 * (ModuleActivationService). Zone/franchise always come from the pickup pin, never from the client.
 */
class Create extends Component
{
    // ── pickup ──
    public string $pickupMode = 'saved'; // saved|pin
    public ?int $pickupAddressId = null;
    public ?float $pickupLat = null;
    public ?float $pickupLng = null;
    public string $pickupLine = '';
    public string $pickupDetail = '';
    public string $pickupContactName = '';
    public string $pickupContactPhone = '';

    // ── drop ──
    public string $dropMode = 'pin'; // pin|saved
    public ?int $dropAddressId = null;
    public ?float $dropLat = null;
    public ?float $dropLng = null;
    public string $dropLine = '';
    public string $dropDetail = '';
    public string $recipientName = '';
    public string $recipientPhone = '';

    // ── package ──
    public string $packageSize = 'small';
    public string $packageWeightKg = '';
    public string $packageDescription = '';
    /** Stage 1: what is inside (a ParcelSettings::CONTENTS key, optional) and the restricted-items tick (required). */
    public string $packageContents = '';
    public bool $restrictedConfirmed = false;
    public string $customerNote = '';
    /** Stage 1: now|scheduled; scheduledAt is the naive wall clock from the datetime-local input (platform timezone). */
    public string $deliveryType = 'now';
    public string $scheduledAt = '';
    public string $paymentMethod = '';
    /** Pay at: pickup|drop (owner decision 2026-10-24) — cash only; drop = the recipient pays the rider at the door. */
    public string $cashCollectAt = 'pickup';

    public string $error = '';

    public function mount(): void
    {
        // Switched off on the Modules screen everywhere = the page does not exist.
        abort_unless(app(ModuleActivationService::class)->isActiveAnywhere(Modules::PARCEL), 404);

        $first = $this->savedAddresses()->first();
        if ($first) {
            $this->pickupAddressId = $first->id;
        } else {
            $this->pickupMode = 'pin'; // the page asks for GPS straight away (autoLocate)
        }

        $this->pickupContactName = (string) auth()->user()->name;
        $this->pickupContactPhone = (string) auth()->user()->phone;

        // Speed (owner 2026-10-24: "2 or 3 taps"): repeat the customer's last choices.
        $last = ParcelOrder::where('customer_id', auth()->id())->latest('id')->first();
        if ($last) {
            $this->packageSize = $last->package_size ?: 'small';
            $this->packageContents = (string) $last->package_contents;
            $this->paymentMethod = (string) $last->payment_method; // dropped in render() if not offered here
            $this->cashCollectAt = $last->cash_collect_at === 'drop' ? 'drop' : 'pickup';
        }
    }

    /**
     * One tap on a "Send again" chip: the drop point and recipient of one of the customer's own recent parcels.
     * Owner-checked; the pin is copied, so the old order's address row is never shared or changed.
     */
    public function useRecent(int $orderId): void
    {
        $order = ParcelOrder::with('dropoffAddress')->where('customer_id', auth()->id())->find($orderId);
        if (! $order || ! $order->dropoffAddress) {
            return;
        }

        $this->setDropPin((float) $order->dropoffAddress->lat, (float) $order->dropoffAddress->lng, (string) $order->dropoffAddress->address_line);
        $this->dropDetail = '';
        $this->recipientName = (string) $order->recipient_name;
        $this->recipientPhone = (string) $order->recipient_phone;
    }

    /** The "Choose from contacts" button (Contact Picker API) — name and number in one tap. */
    public function setRecipient(string $name, string $phone): void
    {
        $this->recipientName = mb_substr(trim($name), 0, 120);
        $digits = preg_replace('/[^0-9+]/', '', $phone);
        // Indian numbers come back as +91XXXXXXXXXX / 0XXXXXXXXXX: keep the 10-digit national number.
        if (preg_match('/^(?:\+?91|0)?([6-9][0-9]{9})$/', $digits, $m)) {
            $digits = $m[1];
        }
        $this->recipientPhone = mb_substr($digits, 0, 15);
        $this->resetErrorBag(['recipientName', 'recipientPhone']);
    }

    /** Up to five distinct recent recipients (by phone), newest first. */
    private function recentRecipients()
    {
        return ParcelOrder::with('dropoffAddress:id,address_line')
            ->where('customer_id', auth()->id())
            ->whereNotNull('recipient_phone')
            ->latest('id')
            ->limit(30)
            ->get(['id', 'recipient_name', 'recipient_phone', 'dropoff_address_id'])
            ->unique(fn ($o) => $o->recipient_phone.'|'.$o->dropoff_address_id)
            ->unique('recipient_phone')
            ->take(5)
            ->values();
    }

    // ═════════════════ pins (called from the page's JS) ═════════════════

    public function setPickupPin(float $lat, float $lng, string $line): void
    {
        $this->assertCoordinates($lat, $lng);
        $this->pickupMode = 'pin';
        $this->pickupLat = $lat;
        $this->pickupLng = $lng;
        $this->pickupLine = mb_substr($line, 0, 255);
        $this->error = '';
    }

    public function setDropPin(float $lat, float $lng, string $line): void
    {
        $this->assertCoordinates($lat, $lng);
        $this->dropMode = 'pin';
        $this->dropLat = $lat;
        $this->dropLng = $lng;
        $this->dropLine = mb_substr($line, 0, 255);
        $this->error = '';
    }

    // ═════════════════ submit ═════════════════

    public function submit(CreateParcelOrderAction $action, ParcelPaymentService $payments): void
    {
        abort_unless(app(ModuleActivationService::class)->isActiveAnywhere(Modules::PARCEL), 404);
        $this->error = '';
        $this->validate($this->rules(), [
            'recipientPhone.regex' => 'Enter a 10-digit mobile number.',
            'pickupContactPhone.regex' => 'Enter a 10-digit mobile number.',
            'restrictedConfirmed.accepted' => 'Confirm the parcel has none of the restricted items.',
            'scheduledAt.required_if' => 'Choose the pickup date and time.',
        ]);

        $pickup = $this->pickupPoint();
        $drop = $this->dropPoint();
        if (! $pickup || ! $drop) {
            $this->error = ! $pickup ? 'Choose where we pick the parcel up.' : 'Choose where we deliver the parcel.';

            return;
        }

        $zone = $pickup['zone'];
        if (! $zone) {
            $this->error = "We don't pick up parcels from that location yet.";

            return;
        }
        if (! array_key_exists($this->paymentMethod, $this->paymentMethods($zone))) {
            $this->error = 'Choose how you want to pay.';

            return;
        }

        try {
            $order = DB::transaction(function () use ($action, $pickup, $drop, $zone) {
                $pickupId = $pickup['address']?->id ?? Address::create([
                    'user_id' => auth()->id(), 'franchise_id' => $zone->franchise_id, 'zone_id' => $zone->id,
                    'label' => 'Parcel pickup', 'lat' => $pickup['lat'], 'lng' => $pickup['lng'],
                    'address_line' => trim($this->pickupDetail.', '.$this->pickupLine, ', '), 'is_parcel_only' => true,
                ])->id;

                $dropZone = $drop['address']?->zone ?? app(CustomerLocationContext::class)->nearestCoveringZone($drop['lat'], $drop['lng']);
                $dropId = $drop['address']?->id ?? Address::create([
                    'user_id' => auth()->id(), 'franchise_id' => $dropZone?->franchise_id, 'zone_id' => $dropZone?->id,
                    'label' => 'Parcel drop — '.($this->recipientName ?: $this->recipientPhone), 'lat' => $drop['lat'], 'lng' => $drop['lng'],
                    'address_line' => trim($this->dropDetail.', '.$this->dropLine, ', '), 'is_parcel_only' => true,
                ])->id;

                return $action->execute([
                    'franchise_id' => $zone->franchise_id,
                    'zone_id' => $zone->id,
                    'customer_id' => auth()->id(),
                    'pickup_address_id' => $pickupId,
                    'dropoff_address_id' => $dropId,
                    'pickup_contact_name' => $this->pickupContactName ?: null,
                    'pickup_contact_phone' => $this->pickupContactPhone ?: null,
                    'recipient_name' => $this->recipientName ?: null,
                    'recipient_phone' => $this->recipientPhone,
                    'package_description' => $this->packageDescription ?: null,
                    'package_weight_kg' => $this->packageWeightKg !== '' ? (float) $this->packageWeightKg : null,
                    'package_size' => $this->packageSize,
                    'package_contents' => $this->packageContents ?: null,
                    'restricted_items_confirmed' => $this->restrictedConfirmed,
                    'delivery_type' => $this->deliveryType,
                    'scheduled_at' => $this->deliveryType === 'scheduled' ? $this->scheduledAt : null,
                    'payment_method' => $this->paymentMethod,
                    'cash_collect_at' => $this->paymentMethod === 'cash' ? $this->cashCollectAt : 'pickup',
                    'customer_note' => $this->customerNote ?: null,
                ]);
            });
        } catch (ModuleNotActiveException) {
            $this->error = 'Parcel delivery is not available from this pickup location yet.';

            return;
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        if ($order->isAwaitingOnlinePayment()) {
            try {
                $checkout = $payments->startOnlinePayment($order);
            } catch (\Throwable $e) {
                report($e);
                // The order exists and waits for payment; the tracking page offers "Pay now" again.
                $this->redirectRoute('customer.parcels.show', $order, navigate: true);

                return;
            }

            $this->dispatch('razorpay-open', order: $checkout, redirect: route('customer.parcels.show', $order))->self();

            return;
        }

        $this->redirectRoute('customer.parcels.show', $order, navigate: true);
    }

    // ═════════════════ helpers ═════════════════

    private function rules(): array
    {
        return [
            'recipientName' => ['nullable', 'string', 'max:120'],
            'recipientPhone' => ['required', 'regex:/^\+?[0-9]{10,15}$/'],
            'pickupContactName' => ['nullable', 'string', 'max:120'],
            'pickupContactPhone' => ['nullable', 'regex:/^\+?[0-9]{10,15}$/'],
            'pickupDetail' => ['nullable', 'string', 'max:150'],
            'dropDetail' => ['nullable', 'string', 'max:150'],
            'packageSize' => ['required', 'in:'.implode(',', array_keys(ParcelSettings::SIZES))],
            'packageWeightKg' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'packageDescription' => ['nullable', 'string', 'max:255'],
            'packageContents' => ['nullable', 'in:'.implode(',', array_keys(ParcelSettings::CONTENTS))],
            'restrictedConfirmed' => ['accepted'],
            'deliveryType' => ['required', 'in:now,scheduled'],
            'scheduledAt' => ['nullable', 'required_if:deliveryType,scheduled', 'string', 'max:20'],
            'customerNote' => ['nullable', 'string', 'max:500'],
            'paymentMethod' => ['required', 'string'],
            'cashCollectAt' => ['required', 'in:pickup,drop'],
        ];
    }

    /** @return array{lat: float, lng: float, address: ?Address, zone: ?Zone}|null */
    private function pickupPoint(): ?array
    {
        if ($this->pickupMode === 'saved') {
            $address = $this->pickupAddressId ? $this->savedAddresses()->firstWhere('id', $this->pickupAddressId) : null;

            return $address ? ['lat' => (float) $address->lat, 'lng' => (float) $address->lng, 'address' => $address, 'zone' => $address->zone] : null;
        }

        if ($this->pickupLat === null || $this->pickupLng === null) {
            return null;
        }

        return [
            'lat' => $this->pickupLat, 'lng' => $this->pickupLng, 'address' => null,
            'zone' => app(CustomerLocationContext::class)->nearestCoveringZone($this->pickupLat, $this->pickupLng),
        ];
    }

    /** @return array{lat: float, lng: float, address: ?Address}|null */
    private function dropPoint(): ?array
    {
        if ($this->dropMode === 'saved') {
            $address = $this->dropAddressId ? $this->savedAddresses()->firstWhere('id', $this->dropAddressId) : null;

            return $address ? ['lat' => (float) $address->lat, 'lng' => (float) $address->lng, 'address' => $address] : null;
        }

        return ($this->dropLat === null || $this->dropLng === null) ? null : ['lat' => $this->dropLat, 'lng' => $this->dropLng, 'address' => null];
    }

    private function savedAddresses()
    {
        return Address::where('user_id', auth()->id())->listed()->with('zone.franchise')
            ->orderByDesc('is_default')->latest()->get();
    }

    private function scopeFor(Zone $zone): array
    {
        $zone->loadMissing('franchise');

        return array_filter([
            'zone_id' => $zone->id, 'franchise_id' => $zone->franchise_id,
            'city_id' => $zone->franchise?->city_id, 'country_id' => $zone->franchise?->country_id,
        ]);
    }

    private function paymentMethods(Zone $zone): array
    {
        $labels = ['online' => 'Pay online (UPI / card)', 'wallet' => 'Wallet', 'cash' => 'Cash'];

        return array_intersect_key($labels, Setting::enabledPaymentMethods(['zone_id' => $zone->id, 'franchise_id' => $zone->franchise_id]));
    }

    private function assertCoordinates(float $lat, float $lng): void
    {
        abort_unless($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180, 422);
    }

    public function render()
    {
        $browsingZone = app(CustomerLocationContext::class)->zone();
        $activeHere = $browsingZone && app(ModuleActivationService::class)->isActive(Modules::PARCEL, $this->scopeFor($browsingZone));

        $pickup = $this->pickupPoint();
        $drop = $this->dropPoint();
        $quote = null;
        $quoteError = null;
        $methods = [];
        $pickupZoneActive = null;
        $scheduleAvailable = false;

        if ($pickup && $pickup['zone']) {
            $scope = $this->scopeFor($pickup['zone']);
            $pickupZoneActive = app(ModuleActivationService::class)->isActive(Modules::PARCEL, $scope);
            $scheduleAvailable = ParcelSettings::scheduleAvailable($scope);
            if (! $scheduleAvailable) {
                $this->deliveryType = 'now';
            }
            $methods = $this->paymentMethods($pickup['zone']);
            if ($this->paymentMethod === '' || ! array_key_exists($this->paymentMethod, $methods)) {
                $this->paymentMethod = (string) array_key_first($methods);
            }

            if ($pickupZoneActive && $drop) {
                try {
                    $quote = app(ParcelPricing::class)->quote(
                        $pickup['lat'], $pickup['lng'], $drop['lat'], $drop['lng'],
                        is_numeric($this->packageWeightKg) ? (float) $this->packageWeightKg : null,
                        $this->packageSize, $scope,
                        $this->deliveryType === 'scheduled',
                    );
                } catch (ParcelQuoteException $e) {
                    $quoteError = $e->getMessage();
                }
            }
        } elseif ($pickup) {
            $pickupZoneActive = false;
        }

        return view('livewire.customer.parcel.create', [
            'activeHere' => $activeHere,
            'browsingZoneKnown' => $browsingZone !== null,
            'recentRecipients' => $this->recentRecipients(),
            'savedAddresses' => $this->savedAddresses(),
            'sizes' => ParcelSettings::SIZES,
            'contentsOptions' => ParcelSettings::CONTENTS,
            'restrictedItems' => ParcelSettings::restrictedItems($pickup && $pickup['zone'] ? $this->scopeFor($pickup['zone']) : []),
            'refusalFee' => ParcelSettings::get('parcel.refusal_fee', $pickup && $pickup['zone'] ? $this->scopeFor($pickup['zone']) : []),
            'scheduleAvailable' => $scheduleAvailable,
            'scheduleMin' => $pickup && $pickup['zone'] ? now(app(TimezoneResolver::class)->platformTimezone())->addMinutes((int) ParcelSettings::get('parcel.schedule_min_lead_minutes', $this->scopeFor($pickup['zone'])))->format('Y-m-d\TH:i') : null,
            'scheduleMax' => $pickup && $pickup['zone'] ? now(app(TimezoneResolver::class)->platformTimezone())->addDays((int) ParcelSettings::get('parcel.schedule_max_days_ahead', $this->scopeFor($pickup['zone'])))->format('Y-m-d\TH:i') : null,
            'quote' => $quote,
            'quoteError' => $quoteError,
            'methods' => $methods,
            'pickupZoneActive' => $pickupZoneActive,
            'maxWeight' => $pickup && $pickup['zone'] ? ParcelSettings::get('parcel.max_weight_kg', $this->scopeFor($pickup['zone'])) : null,
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.customer', ['title' => 'Send a parcel']);
    }
}
