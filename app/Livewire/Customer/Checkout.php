<?php

namespace App\Livewire\Customer;

use App\Actions\CreateBookingBundleAction;
use App\Models\Address;
use App\Models\Setting;
use App\Services\Customer\CatalogPresenter;
use App\Services\Customer\CustomerLocationContext;
use App\Services\Customer\ServiceCartService;
use App\Services\TimezoneResolver;
use App\Services\WalletService;
use App\Support\BookingSchedule;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * The services-cart checkout: one address for the whole cart, a preferred
 * time per line, a server-priced review, then payment. On confirm it builds
 * a `children[]` array (each cart line fanned out `quantity` times) and hands
 * the whole thing to App\Actions\CreateBookingBundleAction — the SAME tested
 * entry point the REST API's BookingBundleController uses. No price, no
 * franchise/zone, is ever taken from the client: franchise/zone come from
 * the chosen address, the charge is the Phase-D cascade computed inside the
 * action, once per child.
 *
 * Wallet is captured synchronously by the action. `online` leaves the bundle
 * `payment_status = pending` and hands off to the bundle page, which opens
 * Razorpay via the same web pattern wallet top-up already uses; capture is
 * the existing /api/webhooks/razorpay handler.
 */
class Checkout extends Component
{
    private const STEPS = ['address', 'schedule', 'review', 'pay'];

    public string $step = 'address';

    public ?int $addressId = null;

    public bool $addingAddress = false;

    public array $newAddress = [
        'label' => 'Home',
        'address_line' => '',
        'landmark' => '',
        'city' => '',
        'pincode' => '',
    ];

    /** cart-item id => 'Y-m-d\TH:i' local string ('' = ASAP). */
    public array $schedules = [];

    public string $paymentMethod = 'wallet';

    public string $error = '';

    /** One key per checkout attempt — a double-submit returns the first bundle instead of booking twice. */
    public string $idempotencyKey = '';

    public function mount(ServiceCartService $cart): void
    {
        if ($cart->lineCount(auth()->user()) < 1) {
            $this->redirectRoute('customer.cart', navigate: true);

            return;
        }

        $tz = app(TimezoneResolver::class);
        foreach ($cart->itemsFor(auth()->user()) as $item) {
            // Stored UTC -> the customer's own wall clock for the
            // datetime-local field, the inverse of BookingSchedule::parse().
            $this->schedules[$item->id] = $tz->toLocalInput($item->scheduled_at) ?? '';
        }

        $this->addressId = Address::where('user_id', auth()->id())
            ->whereNotNull('zone_id')
            ->orderByDesc('is_default')
            ->latest()
            ->first()?->id;

        $this->idempotencyKey = (string) Str::uuid();
    }

    // ---------------------------------------------------------------- steps

    public function next(): void
    {
        if (! $this->stepIsSatisfied($this->step)) {
            return;
        }

        $i = array_search($this->step, self::STEPS, true);
        if ($i < count(self::STEPS) - 1) {
            $this->step = self::STEPS[$i + 1];
            $this->error = '';
        }
    }

    public function back(): void
    {
        $i = array_search($this->step, self::STEPS, true);
        if ($i > 0) {
            $this->step = self::STEPS[$i - 1];
            $this->error = '';
        }
    }

    private function stepIsSatisfied(string $step): bool
    {
        $this->error = '';

        if ($step === 'address' && $this->resolvedAddress() === null) {
            $this->error = 'Choose a service address to continue.';

            return false;
        }

        if ($step === 'schedule' && ($msg = $this->firstScheduleError()) !== null) {
            $this->error = $msg;

            return false;
        }

        return true;
    }

    // ------------------------------------------------------------- address

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

    public function saveNewAddress(): void
    {
        $this->error = '';

        $zone = app(CustomerLocationContext::class)->zone();
        if (! $zone) {
            $this->error = 'Set your area first so we know which team to send.';

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
            'lat' => $zone->center_lat ?? 0,
            'lng' => $zone->center_lng ?? 0,
            'address_line' => $data['address_line'],
            'landmark' => $data['landmark'] ?? null,
            'city' => $data['city'] ?? null,
            'pincode' => $data['pincode'] ?? null,
            'is_default' => false,
        ]);

        $this->addressId = $address->id;
        $this->addingAddress = false;
        $this->reset('newAddress');
        $this->newAddress = ['label' => 'Home', 'address_line' => '', 'landmark' => '', 'city' => '', 'pincode' => ''];
    }

    // ------------------------------------------------------------ schedule

    private function firstScheduleError(): ?string
    {
        foreach ($this->schedules as $value) {
            if (($msg = BookingSchedule::validate($value)) !== null) {
                return $msg;
            }
        }

        return null;
    }

    // ----------------------------------------------------------------- pay

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

    public function place(CreateBookingBundleAction $action, ServiceCartService $cart)
    {
        $this->error = '';

        $address = $this->resolvedAddress();
        if (! $address) {
            $this->step = 'address';
            $this->error = 'Choose a valid service address.';

            return null;
        }

        if (($msg = $this->firstScheduleError()) !== null) {
            $this->step = 'schedule';
            $this->error = $msg;

            return null;
        }

        if (! array_key_exists($this->paymentMethod, $this->enabledPaymentMethods())) {
            $this->error = "The payment method '{$this->paymentMethod}' is not available here.";

            return null;
        }

        $items = $cart->itemsFor(auth()->user());
        if ($items->isEmpty()) {
            return $this->redirectRoute('customer.cart', navigate: true);
        }

        $children = [];
        foreach ($items as $item) {
            $when = $this->schedules[$item->id] ?? '';
            $scheduledAt = BookingSchedule::parse($when)?->format('Y-m-d H:i:s');

            for ($n = 0; $n < max(1, $item->quantity); $n++) {
                $children[] = [
                    'service_id' => $item->service_id,
                    'franchise_id' => $address->franchise_id,
                    'zone_id' => $address->zone_id,
                    'address_id' => $address->id,
                    'scheduled_at' => $scheduledAt,
                    'customer_note' => $item->customer_note,
                ];
            }
        }

        try {
            $bundle = $action->execute([
                'customer_id' => auth()->id(),
                'payment_method' => $this->paymentMethod,
                'idempotency_key' => $this->idempotencyKey,
                'request_fingerprint' => sha1(json_encode($children).'|'.$this->paymentMethod),
                'children' => $children,
            ]);
        } catch (\Throwable $e) {
            // Nothing was created — CreateBookingBundleAction is atomic.
            $this->error = $e->getMessage();

            return null;
        }

        $cart->clear(auth()->user());
        $this->dispatch('cart-updated');

        $needsOnlinePayment = $this->paymentMethod !== 'wallet' && $bundle->payment_status !== 'paid';

        return $this->redirectRoute(
            'customer.bundles.show',
            ['bundle' => $bundle->id, 'pay' => $needsOnlinePayment ? 1 : null],
            navigate: false,
        );
    }

    public function render()
    {
        $cart = app(ServiceCartService::class);
        $presenter = app(CatalogPresenter::class);

        $lines = $cart->itemsFor(auth()->user())->map(function ($item) use ($presenter) {
            $unit = (float) ($presenter->card($item->service)['price'] ?? 0);

            return [
                'item' => $item,
                'unit' => $unit,
                'line' => $unit * $item->quantity,
            ];
        });

        return view('livewire.customer.checkout', [
            'steps' => self::STEPS,
            'lines' => $lines,
            'reviewTotal' => $lines->sum('line'),
            'addresses' => Address::where('user_id', auth()->id())->orderByDesc('is_default')->latest()->get(),
            'enabledMethods' => $this->enabledPaymentMethods(),
            'walletBalance' => $this->walletBalance(),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.customer', ['title' => 'Checkout']);
    }
}
