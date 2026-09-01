<?php

namespace App\Livewire\Customer\Booking;

use App\Actions\CreateBookingAction;
use App\Exceptions\ModuleNotActiveException;
use App\Livewire\Customer\Concerns\ConfiguresServiceOptions;
use App\Models\Address;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Customer\CatalogPresenter;
use App\Services\WalletService;
use App\Support\Modules;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Phase E6 — the customer booking wizard. This is the UI the Phase C
 * ServiceShow page's "Book now" button was always meant to open (its own
 * docblock: "when the wizard lands this is the list it must block on").
 *
 * ── It creates NO booking logic of its own ────────────────────────────────
 * placeBooking() does exactly what App\Http\Controllers\API\BookingController
 * ::store() does — resolve an address the customer actually owns, derive
 * franchise/zone FROM that address (never from the client), check the
 * service is live and the chosen payment method is enabled for the scope,
 * then hand the whole thing to the SAME CreateBookingAction the REST API
 * and the admin call-centre form already use. No price is passed to the
 * Action: Phase D made it authoritative, so the server computes the charge
 * from the database for the scope of the customer's own address. Nothing on
 * this screen adds a price up — the estimate shown is CatalogPresenter's,
 * and it is labelled an estimate, exactly as ServiceShow already labels it.
 *
 * ── Steps ────────────────────────────────────────────────────────────────
 *   configure  choose options (display-only estimate), leave a note
 *   address    pick a saved address, or add one inline
 *   schedule   ASAP, or a datetime within booking.max_schedule_days_ahead
 *   pay        choose wallet / online / cash, confirm
 *
 * Every step re-validates server-side before it will advance; #[Locked] on
 * serviceId so a round-trip can never repoint the wizard at another service.
 */
class Wizard extends Component
{
    use ConfiguresServiceOptions;

    private const STEPS = ['configure', 'address', 'schedule', 'pay'];

    #[Locked]
    public int $serviceId;

    #[Locked]
    public string $step = 'configure';

    public ?int $addressId = null;

    /** null = ASAP (an instant booking); otherwise a 'Y-m-d\TH:i' local datetime from the <input>. */
    public ?string $scheduledAt = null;

    public string $paymentMethod = 'wallet';

    public string $customerNote = '';

    public string $error = '';

    // --- inline "add address" sub-form (mirrors StoreAddressRequest) -------
    public bool $addingAddress = false;

    public array $newAddress = [
        'label' => 'Home',
        'address_line' => '',
        'landmark' => '',
        'city' => '',
        'pincode' => '',
    ];

    /**
     * Set by useCurrentLocationForNewAddress() (Phase 3) when the browser
     * returns a fix that lands inside a served zone. When present,
     * saveNewAddress() stores these real coordinates and this zone instead
     * of the browsing zone + its centre. Null = the manual header-zone path.
     */
    public ?float $newAddressLat = null;
    public ?float $newAddressLng = null;
    public ?int $newAddressZoneId = null;
    public string $newAddressLocatedLabel = '';

    public function mount(Service $service): void
    {
        abort_unless(
            $service->is_active
                && $service->category
                && $service->category->is_active
                && $service->category->module === Modules::SERVICE,
            404,
        );

        $this->serviceId = $service->id;
        $this->preselectRequiredGroups();

        $default = Address::where('user_id', auth()->id())
            ->whereNotNull('zone_id')
            ->orderByDesc('is_default')
            ->latest()
            ->first();
        $this->addressId = $default?->id;
    }

    protected function optionServiceId(): int
    {
        return $this->serviceId;
    }

    // ---------------------------------------------------------------- steps

    public function goTo(string $step): void
    {
        $this->error = '';

        $targetIndex = array_search($step, self::STEPS, true);
        $currentIndex = array_search($this->step, self::STEPS, true);

        // Only ever move forward one gate at a time, or freely backward.
        if ($targetIndex === false || $targetIndex > $currentIndex + 1) {
            return;
        }

        if ($targetIndex > $currentIndex && ! $this->stepIsSatisfied($this->step)) {
            return;
        }

        $this->step = $step;
    }

    public function next(): void
    {
        $currentIndex = array_search($this->step, self::STEPS, true);

        if (! $this->stepIsSatisfied($this->step)) {
            return;
        }

        if ($currentIndex < count(self::STEPS) - 1) {
            $this->step = self::STEPS[$currentIndex + 1];
            $this->error = '';
        }
    }

    public function back(): void
    {
        $currentIndex = array_search($this->step, self::STEPS, true);
        if ($currentIndex > 0) {
            $this->step = self::STEPS[$currentIndex - 1];
            $this->error = '';
        }
    }

    private function stepIsSatisfied(string $step): bool
    {
        if ($step === 'configure' && ! $this->missingRequiredGroups()->isEmpty()) {
            $this->error = 'Please answer every required option before continuing.';

            return false;
        }

        if ($step === 'address' && $this->resolvedAddress() === null) {
            $this->error = 'Choose a service address to continue.';

            return false;
        }

        if ($step === 'schedule' && ($msg = $this->scheduleError()) !== null) {
            $this->error = $msg;

            return false;
        }

        return true;
    }

    // ------------------------------------------------------------- address

    /** The chosen address, only if it really belongs to this customer and can host a booking. */
    private function resolvedAddress(): ?Address
    {
        if (! $this->addressId) {
            return null;
        }

        return Address::where('id', $this->addressId)
            ->where('user_id', auth()->id())
            ->whereNotNull('franchise_id')
            ->whereNotNull('zone_id')
            ->first();
    }

    /**
     * "Use my current location" in the inline add-address form (Phase 3).
     * Unlike the header picker, the zone is resolved from the ADDRESS's own
     * coordinates via nearestCoveringZone() — the one resolver this codebase
     * has. A hit stashes the real lat/lng + that zone for saveNewAddress();
     * a miss says so plainly and leaves the form on the manual header-zone
     * path (no partial state, no silently zone-less address).
     */
    public function useCurrentLocationForNewAddress(float $lat, float $lng): void
    {
        $this->error = '';

        validator(
            ['lat' => $lat, 'lng' => $lng],
            ['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']],
        )->validate();

        $zone = app(\App\Services\Customer\CustomerLocationContext::class)->nearestCoveringZone($lat, $lng);

        if (! $zone) {
            $this->reset('newAddressLat', 'newAddressLng', 'newAddressZoneId', 'newAddressLocatedLabel');
            $this->error = "We don't have a team serving that exact spot yet — type the address and pick your area from the top of the page.";

            return;
        }

        $this->newAddressLat = $lat;
        $this->newAddressLng = $lng;
        $this->newAddressZoneId = $zone->id;
        $this->newAddressLocatedLabel = $zone->name;
    }

    /**
     * Add an address inline. Zone + franchise are derived server-side, never
     * accepted from the form — same rule as AddressController::store(). The
     * zone is the one useCurrentLocationForNewAddress() resolved from the
     * pin, if the customer used it; otherwise the active browsing zone
     * (CustomerLocationContext). lat/lng are the real pinned coordinates
     * when available, else the zone centre (the codebase has no geocoder).
     */
    public function saveNewAddress(): void
    {
        $this->error = '';

        $zone = $this->newAddressZoneId
            ? \App\Models\Zone::where('is_active', true)->find($this->newAddressZoneId)
            : app(\App\Services\Customer\CustomerLocationContext::class)->zone();

        if (! $zone) {
            $this->error = 'Choose your area from the top of the page first, so we know which team serves this address.';

            return;
        }

        $data = $this->validate([
            'newAddress.label' => ['required', 'string', 'max:40'],
            'newAddress.address_line' => ['required', 'string', 'max:255'],
            'newAddress.landmark' => ['nullable', 'string', 'max:120'],
            'newAddress.city' => ['nullable', 'string', 'max:80'],
            'newAddress.pincode' => ['nullable', 'string', 'max:12'],
        ])['newAddress'];

        $address = Address::create([
            'user_id' => auth()->id(),
            'franchise_id' => $zone->franchise_id,
            'zone_id' => $zone->id,
            'label' => $data['label'],
            'lat' => $this->newAddressLat ?? $zone->center_lat ?? 0,
            'lng' => $this->newAddressLng ?? $zone->center_lng ?? 0,
            'address_line' => $data['address_line'],
            'landmark' => $data['landmark'] ?? null,
            'city' => $data['city'] ?? null,
            'pincode' => $data['pincode'] ?? null,
            'is_default' => false,
        ]);

        $this->addressId = $address->id;
        $this->addingAddress = false;
        $this->reset('newAddress', 'newAddressLat', 'newAddressLng', 'newAddressZoneId', 'newAddressLocatedLabel');
        $this->newAddress = ['label' => 'Home', 'address_line' => '', 'landmark' => '', 'city' => '', 'pincode' => ''];
    }

    /**
     * Recovery for an address whose zone_id / franchise_id were nulled — the
     * usual cause being its zone deleted and re-created with a new id, so the
     * nullOnDelete FK blanked the reference (see Zones\Manage::confirmDelete's
     * blocker, added the same session). Re-links the address to the customer's
     * current header area, deriving franchise from that zone — exactly
     * AddressController::update()'s zone re-link. No geocoding: this codebase
     * has no point-in-polygon resolver, and the header zone is the same
     * explicit choice every other address writer here already uses.
     */
    public function assignCurrentAreaToAddress(int $addressId): void
    {
        $this->error = '';

        $address = Address::where('id', $addressId)->where('user_id', auth()->id())->first();
        if (! $address) {
            return;
        }

        $zone = app(\App\Services\Customer\CustomerLocationContext::class)->zone();
        if (! $zone) {
            $this->error = 'Choose your area from the top of the page first, so we know which team serves this address.';

            return;
        }

        $address->update([
            'zone_id' => $zone->id,
            'franchise_id' => $zone->franchise_id,
        ]);

        $this->addressId = $address->id;
    }

    // ------------------------------------------------------------ schedule

    private function maxScheduleDays(): int
    {
        return \App\Support\BookingSchedule::maxDays();
    }

    /** Null when the schedule is valid (ASAP or an in-window datetime), else the message to show. */
    private function scheduleError(): ?string
    {
        return \App\Support\BookingSchedule::validate($this->scheduledAt);
    }

    // ----------------------------------------------------------------- pay

    /**
     * The payment methods actually enabled for this address's scope — the
     * exact same Setting::enabledPaymentMethods() call BookingController
     * ::store() gates on, so the wizard can never offer one the Action will
     * reject.
     *
     * @return array<string, string>
     */
    public function enabledPaymentMethods(): array
    {
        $address = $this->resolvedAddress();
        if (! $address) {
            return [];
        }

        return Setting::enabledPaymentMethods([
            'zone_id' => $address->zone_id,
            'franchise_id' => $address->franchise_id,
        ]);
    }

    public function walletBalance(): float
    {
        return app(WalletService::class)->balance(auth()->user());
    }

    public function placeBooking(CreateBookingAction $action): void
    {
        $this->error = '';

        if (! $this->missingRequiredGroups()->isEmpty()) {
            $this->step = 'configure';
            $this->error = 'Please answer every required option before booking.';

            return;
        }

        $address = $this->resolvedAddress();
        if (! $address) {
            $this->step = 'address';
            $this->error = 'Choose a valid service address.';

            return;
        }

        if (($msg = $this->scheduleError()) !== null) {
            $this->step = 'schedule';
            $this->error = $msg;

            return;
        }

        $service = Service::where('id', $this->serviceId)->where('is_active', true)->first();
        if (! $service) {
            $this->error = 'This service is no longer available.';

            return;
        }

        if (! array_key_exists($this->paymentMethod, $this->enabledPaymentMethods())) {
            $this->error = "The payment method '{$this->paymentMethod}' is not available here.";

            return;
        }

        try {
            $booking = $action->execute([
                'franchise_id' => $address->franchise_id,
                'zone_id' => $address->zone_id,
                'customer_id' => auth()->id(),
                'service_id' => $service->id,
                'address_id' => $address->id,
                // Naive wall clock the customer picked -> UTC instant, same
                // boundary conversion the cart/checkout path applies.
                'scheduled_at' => \App\Support\BookingSchedule::parse($this->scheduledAt),
                'payment_method' => $this->paymentMethod,
                'customer_note' => $this->customerNote ?: null,
            ]);
        } catch (ModuleNotActiveException $e) {
            $this->error = $e->getMessage();

            return;
        } catch (\RuntimeException $e) {
            // e.g. wallet payments disabled for this scope, or an
            // insufficient-balance WalletService rejection — the booking was
            // NOT created (CreateBookingAction is atomic).
            $this->error = $e->getMessage();

            return;
        }

        $this->redirectRoute('customer.orders.show', ['booking' => $booking->id], navigate: true);
    }

    public function render()
    {
        $service = Service::with(['category', 'subcategory'])->findOrFail($this->serviceId);
        $presenter = app(CatalogPresenter::class);
        $card = $presenter->card($service);
        $selectedOptions = $this->selectedOptions();

        return view('livewire.customer.booking.wizard', [
            'service' => $service,
            'card' => $card,
            'currencySymbol' => $presenter->currencySymbol(),
            'groups' => $this->optionGroups(),
            'selectedOptions' => $selectedOptions,
            'optionsEstimate' => $this->optionsTotal($selectedOptions),
            'baseEstimate' => (float) $card['price'],
            'addresses' => Address::where('user_id', auth()->id())->orderByDesc('is_default')->latest()->get(),
            'enabledMethods' => $this->enabledPaymentMethods(),
            'walletBalance' => $this->walletBalance(),
            'steps' => self::STEPS,
        ])->layout('components.layouts.customer', ['title' => 'Book '.$service->name]);
    }
}
