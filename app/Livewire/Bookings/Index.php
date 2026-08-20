<?php

namespace App\Livewire\Bookings;

use App\Actions\CreateBookingAction;
use App\Models\Address;
use App\Models\Booking;
use App\Models\FranchiseServicePricing;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Services\Ai\BookingNaturalLanguageFilter;
use App\Services\AuthorizationService;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $statusFilter = '';
    public string $search = '';

    // ====================== Natural-language search ======================
    // Admin Polish + AI session, Part 2 item 2. A free-text box that maps to
    // the SAME statusFilter/zone/date filters below — never a separate query
    // path, never raw SQL from the LLM (there is no LLM call at all here;
    // see BookingNaturalLanguageFilter's own docblock for why a deterministic
    // parser is the correct, safer choice for this specific mapping).
    public string $nlQuery = '';
    public ?int $nlZoneId = null;
    public ?string $nlDateFrom = null;
    public ?string $nlDateTo = null;
    /** @var array<int, string> */
    public array $nlMatched = [];

    protected $queryString = ['statusFilter', 'search'];

    /** bookings.view was seeded (2026_08_11_016000) but never checked on this list screen (only the createBooking() panel checks bookings.create) -- see Commissions\Index's identical fix for the full reasoning. */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('bookings.view'), 403, 'You do not have permission to view bookings.');
    }

    // ============================ New Booking ============================
    // Same one-screen pattern as Categories/Subcategories/Services: an "Add
    // New" panel pinned at the top of the page, list live below it — no
    // modal. Services vertical only, per the Master Context doc §47 — this
    // drives the real app/Actions/CreateBookingAction.php pipeline (order
    // code, pricing, ServiceMatchingJob dispatch), it is not a parallel
    // booking implementation. Nothing here bypasses that Action.

    // --- Customer: search existing, or create inline ---
    public string $customerSearch = '';
    public ?int $selectedCustomerId = null;
    public bool $showNewCustomerForm = false;
    public string $newCustomerName = '';
    public string $newCustomerPhone = '';

    // --- Zone (drives franchise — no separate franchise picker) ---
    public ?int $selectedZoneId = null;

    // --- Address: pick an existing one for the customer, or add new ---
    public ?int $selectedAddressId = null;
    public bool $showNewAddressForm = false;
    public string $newAddressLabel = 'Home';
    public string $newAddressLine = '';
    public string $newAddressLandmark = '';
    public string $newAddressCity = '';
    public string $newAddressPincode = '';
    public ?float $newAddressLat = null;
    public ?float $newAddressLng = null;

    // --- Service & price ---
    public ?int $selectedServiceId = null;
    public ?string $priceQuoted = null;

    // --- Scheduling, payment, note ---
    public string $bookingType = 'instant'; // instant|scheduled
    public ?string $scheduledAt = null;
    public string $paymentMethod = 'online';
    public string $customerNote = '';

    // --- Success banner after creation (code + link, form clears, list stays put) ---
    public ?array $newBookingFlash = null;

    public function updatingStatusFilter()
    {
        $this->resetPage();
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    /**
     * Fired on nlQuery's own debounced wire:model.live — parses the typed
     * text against the SAME zones list the list screen itself already
     * scopes to (only zones this admin can see), and sets the real,
     * enum-backed filter properties render() already knows how to apply.
     * Never builds or executes a query itself.
     */
    public function updatedNlQuery(): void
    {
        if (trim($this->nlQuery) === '') {
            $this->clearNlQuery();
            return;
        }

        // Production-hardening session, Part 2 authorization spot-check —
        // this used to be a bare Zone::where('is_active', true)->get(...),
        // contradicting this very method's own docblock ("the SAME zones
        // list the list screen itself already scopes to") and this
        // parser's own contract (BookingNaturalLanguageFilter::parse()'s
        // docblock: "Real, already-scoped zones ... never a fresh
        // unscoped query"). A franchise/zone-scoped admin typing e.g.
        // "zone 12" could resolve and see another franchise's real zone
        // name/code echoed into the "Understood: zone: ..." chip below —
        // no Booking row data leaked (the resulting filter is ANDed onto
        // the already-scoped query and would just match zero rows), but
        // the zone's own name/code is real cross-tenant information
        // disclosure. Scoped here via the exact same AuthorizationService
        // convention every other query on this screen already uses.
        $zones = app(AuthorizationService::class)
            ->scopeQuery(Zone::query(), auth()->user(), 'bookings.view', $this->zoneListScopeColumns())
            ->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'franchise_id']);
        $result = app(BookingNaturalLanguageFilter::class)->parse($this->nlQuery, $zones);

        $this->statusFilter = $result['status'] ?? '';
        $this->nlZoneId = $result['zone_id'];
        $this->nlDateFrom = $result['date_from']?->toDateTimeString();
        $this->nlDateTo = $result['date_to']?->toDateTimeString();
        $this->nlMatched = $result['matched'];
        $this->resetPage();
    }

    public function clearNlQuery(): void
    {
        $this->nlQuery = '';
        $this->statusFilter = '';
        $this->nlZoneId = null;
        $this->nlDateFrom = null;
        $this->nlDateTo = null;
        $this->nlMatched = [];
        $this->resetPage();
    }

    /** A manual status-chip click fully overrides whatever a previous natural-language search set — never combines with a leftover zone/date filter the admin can no longer see reflected in the chip UI itself. */
    public function selectStatus(string $status): void
    {
        $this->nlQuery = '';
        $this->nlZoneId = null;
        $this->nlDateFrom = null;
        $this->nlDateTo = null;
        $this->nlMatched = [];
        $this->statusFilter = $status;
        $this->resetPage();
    }

    /**
     * A zone's position in the geography cascade — same shape as
     * Bookings\Show::bookingScope(), built from a Zone rather than an
     * existing Booking since none exists yet at this point in the flow.
     */
    private function zoneScope(Zone $zone): array
    {
        $zone->loadMissing('franchise');

        return array_filter([
            'zone_id' => $zone->id,
            'franchise_id' => $zone->franchise_id,
            'city_id' => $zone->franchise?->city_id,
            'country_id' => $zone->franchise?->country_id,
        ]);
    }

    /**
     * Real consumer for Settings > Payment's enabled-methods toggles.
     * Falls back to all three if every method were somehow disabled at
     * once (savePayment() on the Settings screen already refuses to save
     * that state, but this stays defensive rather than offering an empty
     * dropdown if a scope override ever produces it).
     */
    private function enabledPaymentMethods(): array
    {
        $methods = [
            'online' => 'Online',
            'cash' => 'Cash',
            'wallet' => 'Wallet',
        ];

        $enabled = array_filter($methods, fn ($label, $slug) =>
            Setting::get("payment.{$slug}_enabled", '1') === '1', ARRAY_FILTER_USE_BOTH);

        return $enabled ?: $methods;
    }

    /**
     * zone_id/franchise_id are direct Booking columns; city_id/country_id
     * only resolve through the booking's franchise -- same ancestry
     * Bookings\Show::bookingScope() already builds per-row for the
     * reassign/cancel checks, expressed here as a column map so
     * AuthorizationService::scopeQuery() can filter the LIST the same way.
     */
    private function bookingScopeColumns(): array
    {
        return ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
    }

    /** Same shape as bookingScopeColumns(), expressed for a Zone row directly (zone's own id, not a foreign key to it) — used to scope the NL-search zone lookup in updatedNlQuery(). */
    private function zoneListScopeColumns(): array
    {
        return ['zone_id' => 'id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];
    }

    public function render()
    {
        $scoped = fn ($query) => app(AuthorizationService::class)->scopeQuery($query, auth()->user(), 'bookings.view', $this->bookingScopeColumns());

        $bookings = $scoped(Booking::with(['customer', 'service', 'provider.user']))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search, fn ($q) => $q->where('code', 'like', "%{$this->search}%"))
            // Natural-language search results (Part 2 item 2) -- zone_id and
            // created_at are real Booking columns, applied through the exact
            // same query builder as every other filter on this screen, never
            // a separate path.
            ->when($this->nlZoneId, fn ($q) => $q->where('zone_id', $this->nlZoneId))
            ->when($this->nlDateFrom, fn ($q) => $q->where('created_at', '>=', $this->nlDateFrom))
            ->when($this->nlDateTo, fn ($q) => $q->where('created_at', '<=', $this->nlDateTo))
            ->latest()
            ->paginate(20);

        $statusCounts = $scoped(Booking::query())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('livewire.bookings.index', [
            'bookings' => $bookings,
            'statusCounts' => $statusCounts,
            'zones' => Zone::with('franchise')
                ->where('is_active', true)
                ->whereHas('franchise.modules', fn ($q) => $q->where('service', true))
                ->orderBy('name')
                ->get(),
            'services' => Service::where('is_active', true)->orderBy('name')->get(),
            'mapsConfigured' => (bool) config('services.google_maps.key'),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
            'enabledPaymentMethods' => $this->enabledPaymentMethods(),
        ])->layout('layouts.admin', ['title' => 'Bookings']);
    }

    // ============================ Reset form ============================

    public function clearNewBookingForm(): void
    {
        $this->resetNewBookingForm();
        $this->newBookingFlash = null;
    }

    private function resetNewBookingForm(): void
    {
        $this->reset([
            'customerSearch', 'selectedCustomerId', 'showNewCustomerForm',
            'newCustomerName', 'newCustomerPhone',
            'selectedZoneId',
            'selectedAddressId', 'showNewAddressForm',
            'newAddressLabel', 'newAddressLine', 'newAddressLandmark',
            'newAddressCity', 'newAddressPincode', 'newAddressLat', 'newAddressLng',
            'selectedServiceId', 'priceQuoted',
            'bookingType', 'scheduledAt', 'paymentMethod', 'customerNote',
        ]);
        $this->newAddressLabel = 'Home';
        $this->bookingType = 'instant';
        $this->paymentMethod = 'online';
        $this->resetValidation();
    }

    // ============================== Customer ==============================

    public function getMatchingCustomersProperty()
    {
        if ($this->selectedCustomerId || mb_strlen(trim($this->customerSearch)) < 2) {
            return collect();
        }

        return User::where('role', 'customer')
            ->where(function ($q) {
                $q->where('phone', 'like', '%'.$this->customerSearch.'%')
                  ->orWhere('name', 'like', '%'.$this->customerSearch.'%');
            })
            ->orderBy('name')
            ->limit(8)
            ->get();
    }

    public function getSelectedCustomerProperty(): ?User
    {
        if (! $this->selectedCustomerId) {
            return null;
        }

        return User::with('addresses')->find($this->selectedCustomerId);
    }

    public function selectCustomer(int $customerId): void
    {
        $this->selectedCustomerId = $customerId;
        $this->customerSearch = '';
        $this->showNewCustomerForm = false;
        $this->selectedAddressId = null;
        $this->showNewAddressForm = false;
    }

    public function clearSelectedCustomer(): void
    {
        $this->selectedCustomerId = null;
        $this->selectedAddressId = null;
        $this->showNewAddressForm = false;
    }

    public function toggleNewCustomerForm(): void
    {
        $this->showNewCustomerForm = ! $this->showNewCustomerForm;
        $this->newCustomerName = '';
        $this->newCustomerPhone = '';
        $this->resetValidation(['newCustomerName', 'newCustomerPhone']);
    }

    public function createCustomer(): void
    {
        // Customers are global records (no franchise column), and this step
        // runs before a zone is necessarily chosen — there's no specific
        // target to scope against yet. canAnywhere() confirms the user holds
        // bookings.create in at least one assignment (blocking a Support-only
        // actor, who never gets it); the real, scoped check happens at
        // createBooking() below once a zone is actually picked.
        if (! auth()->user()->hasPermissionAnywhere('bookings.create')) {
            $this->addError('permission', 'You do not have permission to create bookings.');
            return;
        }

        $this->validate([
            'newCustomerName' => ['required', 'string', 'max:255'],
            'newCustomerPhone' => ['required', 'string', 'max:20', 'unique:users,phone'],
        ], [], [
            'newCustomerName' => 'name',
            'newCustomerPhone' => 'phone',
        ]);

        $customer = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => $this->newCustomerName,
            'phone' => $this->newCustomerPhone,
            'role' => 'customer',
            'status' => 'active',
            'preferred_language' => 'en',
        ]);

        $this->selectCustomer($customer->id);
    }

    // =============================== Address ===============================

    public function selectAddress(int $addressId): void
    {
        $this->selectedAddressId = $addressId;
        $this->showNewAddressForm = false;
    }

    public function toggleNewAddressForm(): void
    {
        if (! $this->selectedZoneId) {
            $this->addError('selectedZoneId', 'Pick a zone first — the new address is placed relative to it.');
            return;
        }

        $this->showNewAddressForm = ! $this->showNewAddressForm;
        $this->newAddressLabel = 'Home';
        $this->newAddressLine = '';
        $this->newAddressLandmark = '';
        $this->newAddressCity = '';
        $this->newAddressPincode = '';
        $this->newAddressLat = null;
        $this->newAddressLng = null;
        $this->resetValidation([
            'newAddressLine', 'newAddressLat', 'newAddressLng', 'selectedZoneId',
        ]);
    }

    public function addNewAddress(): void
    {
        $this->validate([
            'selectedZoneId' => ['required', 'exists:zones,id'],
            'newAddressLine' => ['required', 'string', 'max:255'],
            'newAddressLat' => ['required', 'numeric', 'between:-90,90'],
            'newAddressLng' => ['required', 'numeric', 'between:-180,180'],
            'newAddressPincode' => ['nullable', 'string', 'max:10'],
        ], [], [
            'selectedZoneId' => 'zone',
            'newAddressLine' => 'address',
            'newAddressLat' => 'map location',
            'newAddressLng' => 'map location',
        ]);

        if (! $this->selectedCustomerId) {
            $this->addError('selectedCustomerId', 'Pick or create a customer before adding an address.');
            return;
        }

        $zone = Zone::findOrFail($this->selectedZoneId);

        if (! auth()->user()->hasPermission('bookings.create', $this->zoneScope($zone))) {
            $this->addError('permission', 'You do not have permission to create bookings in this zone.');
            return;
        }

        $address = Address::create([
            'user_id' => $this->selectedCustomerId,
            'franchise_id' => $zone->franchise_id,
            'zone_id' => $zone->id,
            'label' => $this->newAddressLabel ?: 'Home',
            'lat' => $this->newAddressLat,
            'lng' => $this->newAddressLng,
            'address_line' => $this->newAddressLine,
            'landmark' => $this->newAddressLandmark ?: null,
            'city' => $this->newAddressCity ?: null,
            'pincode' => $this->newAddressPincode ?: null,
        ]);

        $this->selectAddress($address->id);
    }

    // ================================ Zone ================================

    public function getSelectedZoneProperty(): ?Zone
    {
        if (! $this->selectedZoneId) {
            return null;
        }

        return Zone::with('franchise')->find($this->selectedZoneId);
    }

    // =============================== Service ===============================

    public function updatedSelectedServiceId(): void
    {
        if (! $this->selectedServiceId) {
            $this->priceQuoted = null;
            return;
        }

        $service = Service::find($this->selectedServiceId);

        if (! $service) {
            return;
        }

        $franchiseId = $this->selectedZone?->franchise_id;

        $override = $franchiseId
            ? FranchiseServicePricing::where('franchise_id', $franchiseId)
                ->where('service_id', $service->id)
                ->where('is_offered', true)
                ->whereNotNull('price_override')
                ->value('price_override')
            : null;

        $this->priceQuoted = (string) ($override ?? $service->discount_price ?? $service->base_price);
    }

    // ============================= Create booking =============================

    public function createBooking(): void
    {
        // How far ahead a scheduled booking may be placed — admin-editable via
        // the Booking Settings tab, default 14 days.
        $maxScheduleDays = (int) Setting::get('booking.max_schedule_days_ahead', 14);

        // Re-validated server-side against the Settings > Payment toggles,
        // not just filtered out of the dropdown — a disabled method must be
        // rejected even if submitted directly, not merely hidden from view.
        $enabledMethods = implode(',', array_keys($this->enabledPaymentMethods()));

        $this->validate([
            'selectedCustomerId' => ['required', 'integer', 'exists:users,id'],
            'selectedZoneId' => ['required', 'integer', 'exists:zones,id'],
            'selectedAddressId' => ['required', 'integer', 'exists:addresses,id'],
            'selectedServiceId' => ['required', 'integer', 'exists:services,id'],
            'priceQuoted' => ['required', 'numeric', 'min:0'],
            'paymentMethod' => ['required', "in:{$enabledMethods}"],
            'scheduledAt' => [
                $this->bookingType === 'scheduled' ? 'required' : 'nullable',
                'date', 'after:now',
                'before_or_equal:'.now()->addDays($maxScheduleDays)->toDateTimeString(),
            ],
        ], [], [
            'selectedCustomerId' => 'customer',
            'selectedZoneId' => 'zone',
            'selectedAddressId' => 'address',
            'selectedServiceId' => 'service',
            'priceQuoted' => 'price',
            'scheduledAt' => 'scheduled time',
        ]);

        $zone = Zone::findOrFail($this->selectedZoneId);

        if (! auth()->user()->hasPermission('bookings.create', $this->zoneScope($zone))) {
            $this->addError('permission', 'You do not have permission to create bookings in this zone.');
            return;
        }

        $booking = app(CreateBookingAction::class)->execute([
            'franchise_id' => $zone->franchise_id,
            'zone_id' => $zone->id,
            'customer_id' => $this->selectedCustomerId,
            'service_id' => $this->selectedServiceId,
            'address_id' => $this->selectedAddressId,
            'scheduled_at' => $this->bookingType === 'scheduled' ? $this->scheduledAt : null,
            'price_quoted' => $this->priceQuoted,
            'payment_method' => $this->paymentMethod,
            'customer_note' => $this->customerNote ?: null,
        ]);

        $this->newBookingFlash = ['code' => $booking->code, 'id' => $booking->id];
        $this->resetNewBookingForm();
        $this->resetPage();
    }
}
