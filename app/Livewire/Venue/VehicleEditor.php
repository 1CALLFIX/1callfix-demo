<?php

namespace App\Livewire\Venue;

use App\Livewire\Venue\Concerns\InteractsWithHalls;
use App\Models\VehicleListing;
use App\Models\Zone;
use App\Services\ActivityLogger;
use App\Services\Homes\ListingFeeService;
use App\Services\WalletService;
use App\Support\ImageShrinker;
use App\Support\VehicleListingSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Rental portal → one vehicle for rent (owner 2026-11-18, OLX-style). Five numbered, coloured steps; the kind, fuel, gears,
 * seats, how it is rented, area, features and rules are taps from the Rental Settings lists for the vehicle's own area.
 * The owner types only what only they know: the make and model, the price, a landmark and a few lines.
 *
 * Draft / Needs changes: everything changes at once, then "Send for review". Waiting for review: locked. Approved:
 * prices, features and rules change at once; photos, description and the pickup address wait for the team while the
 * listing stays up with the old ones. "Not available now" hides it; "Available again" shows it. The title ("Maruti Swift
 * car for rent in Magunta Layout") is made from the taps, never typed.
 */
class VehicleEditor extends Component
{
    use InteractsWithHalls;
    use WithFileUploads;

    /** Seat buttons; the owner can still type another number. */
    public const SEAT_CHOICES = [2, 4, 5, 7, 9, 12];

    #[Locked]
    public ?int $listingId = null;

    public string $kind = '';
    public string $make = '';
    public string $model = '';
    public string $year = '';
    public string $fuel = '';
    public string $transmission = '';
    public string $seats = '';
    /** @var list<string> */
    public array $driverOptions = [];
    public string $priceDay = '';
    public string $priceHour = '';
    public string $deposit = '';
    public string $kmPerDay = '';
    public string $zoneId = '';
    public string $landmark = '';
    public string $addressLine = '';
    public string $lat = '';
    public string $lng = '';
    /** @var list<string> */
    public array $features = [];
    /** @var list<string> */
    public array $ruleList = [];
    public string $about = '';

    public $cover = null;
    /** @var array<int, mixed> */
    public array $newPhotos = [];
    /** @var list<string> */
    public array $photos = [];

    public string $notice = '';

    public function mount(?int $listingId = null): void
    {
        $partner = $this->partner();
        if ($listingId !== null) {
            $v = $this->ownVehicle($listingId);
            $pending = fn (string $field) => array_key_exists($field, (array) $v->pending_changes) ? $v->pending_changes[$field] : $v->{$field};
            $this->listingId = $v->id;
            $this->kind = (string) $v->vehicle_kind;
            $this->make = (string) $v->make;
            $this->model = (string) $v->model;
            $this->year = $v->year ? (string) $v->year : '';
            $this->fuel = (string) $v->fuel;
            $this->transmission = (string) $v->transmission;
            $this->seats = $v->seats ? (string) $v->seats : '';
            $this->driverOptions = array_values((array) ($v->driver_options ?? []));
            $this->priceDay = $v->price_day !== null ? (string) (float) $v->price_day : '';
            $this->priceHour = $v->price_hour !== null ? (string) (float) $v->price_hour : '';
            $this->deposit = $v->deposit !== null ? (string) (float) $v->deposit : '';
            $this->kmPerDay = $v->km_per_day ? (string) $v->km_per_day : '';
            $this->zoneId = $v->zone_id ? (string) $v->zone_id : '';
            $this->landmark = (string) $pending('landmark');
            $this->addressLine = (string) $pending('address_line');
            $this->lat = $pending('lat') !== null ? (string) (float) $pending('lat') : '';
            $this->lng = $pending('lng') !== null ? (string) (float) $pending('lng') : '';
            $this->features = array_values((array) ($v->features ?? []));
            $this->ruleList = array_values((array) ($v->rule_list ?? []));
            $this->about = (string) $pending('about');
            $this->photos = array_values((array) ($pending('photos') ?? []));
        } else {
            $this->zoneId = $partner->zone_id ? (string) $partner->zone_id : '';
        }
    }

    private function vehicle(): ?VehicleListing
    {
        return $this->listingId ? $this->ownVehicle($this->listingId) : null;
    }

    protected function ownVehicle(int $id): VehicleListing
    {
        return VehicleListing::with('franchise')->where('provider_id', $this->partner()->id)->find($id) ?? abort(404);
    }

    private function franchiseId(): ?int
    {
        return $this->vehicle()?->franchise_id ?? $this->partner()->franchise_id;
    }

    /** Settings scope for the lists: the area picked now, its franchise, city and country. */
    private function scope(): array
    {
        $franchise = \App\Models\Franchise::find($this->franchiseId());

        return array_filter([
            'zone_id' => $this->zoneId !== '' ? (int) $this->zoneId : null, 'franchise_id' => $franchise?->id,
            'city_id' => $franchise?->city_id, 'country_id' => $franchise?->country_id,
        ]);
    }

    /** A list from Rental Settings for this area, plus what this vehicle already has (a value taken off later stays valid). */
    private function choices(string $key, array $current = []): array
    {
        return array_values(array_unique(array_merge(VehicleListingSettings::listIn($key, $this->scope()), array_filter($current))));
    }

    public function pickSeats(int $seats): void
    {
        $this->seats = (string) $seats;
    }

    public function removePhoto(int $index): void
    {
        unset($this->photos[$index]);
        $this->photos = array_values($this->photos);
    }

    private function rules(): array
    {
        $scope = $this->scope();
        $v = $this->vehicle();

        return [
            'kind' => ['required', Rule::in($this->choices('vehicles.kinds', [(string) $v?->vehicle_kind]))],
            'make' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'year' => ['nullable', 'integer', 'min:1980', 'max:'.(now()->year + 1)],
            'fuel' => ['required', Rule::in($this->choices('vehicles.fuels', [(string) $v?->fuel]))],
            'transmission' => ['nullable', Rule::in($this->choices('vehicles.transmissions', [(string) $v?->transmission]))],
            'seats' => ['nullable', 'integer', 'min:1', 'max:80'],
            'driverOptions' => ['required', 'array', 'min:1'],
            'driverOptions.*' => [Rule::in($this->choices('vehicles.driver_options', (array) ($v?->driver_options ?? [])))],
            'priceDay' => ['required', 'numeric', 'min:'.(int) VehicleListingSettings::get('vehicles.min_price_day', $scope), 'max:'.(int) VehicleListingSettings::get('vehicles.max_price_day', $scope)],
            'priceHour' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'deposit' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'kmPerDay' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'zoneId' => ['required', Rule::in(Zone::where('franchise_id', $this->franchiseId())->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'landmark' => ['nullable', 'string', 'max:160'],
            'addressLine' => ['required', 'string', 'min:6', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'features' => ['array'],
            'features.*' => [Rule::in($this->choices('vehicles.features', (array) ($v?->features ?? [])))],
            'ruleList' => ['array'],
            'ruleList.*' => [Rule::in($this->choices('vehicles.rules', (array) ($v?->rule_list ?? [])))],
            'about' => ['nullable', 'string', 'max:3000'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'newPhotos' => ['array', 'max:'.VehicleListing::MAX_PHOTOS],
            'newPhotos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ];
    }

    private function messages(): array
    {
        return [
            'kind.required' => 'Tap the kind of vehicle.',
            'fuel.required' => 'Tap the fuel.',
            'driverOptions.required' => 'Tap how you rent it: self-drive, with driver, or both.',
            'driverOptions.min' => 'Tap how you rent it: self-drive, with driver, or both.',
            'priceDay.required' => 'Enter the price per day.',
            'priceDay.min' => 'The price looks too low. Check the amount.',
            'priceDay.max' => 'The price looks too high. Check the amount.',
            'year.min' => 'Check the year.',
            'year.max' => 'Check the year.',
            'zoneId.required' => 'Tap the area where the vehicle is.',
            'zoneId.in' => 'Tap the area where the vehicle is.',
            'addressLine.required' => 'Enter the pickup address. Customers see only the area, never this address.',
            'newPhotos.max' => 'Up to '.VehicleListing::MAX_PHOTOS.' photos.',
        ];
    }

    public function save(): ?VehicleListing
    {
        $this->reset('notice');
        $existing = $this->vehicle();
        if ($existing?->status === 'in_review') {
            $this->addError('vehicle', 'We are checking this vehicle. You can change it again once we reply.');

            return null;
        }
        $this->validate($this->rules(), $this->messages());
        if (count($this->photos) + count($this->newPhotos) > VehicleListing::MAX_PHOTOS) {
            $this->addError('newPhotos', 'Up to '.VehicleListing::MAX_PHOTOS.' photos. Remove some first.');

            return null;
        }

        $partner = $this->partner();
        $folder = 'vehicles/'.($existing?->id ?? 'new-'.$partner->id);
        $cover = $this->cover ? ImageShrinker::store($this->cover, $folder, 'hall_photo') : null;
        $photos = $this->photos;
        foreach ($this->newPhotos as $file) {
            $photos[] = ImageShrinker::store($file, $folder, 'hall_photo');
        }
        $zone = Zone::find((int) $this->zoneId);
        $text = fn (string $v) => trim($v) !== '' ? trim($v) : null;
        $number = fn (string $v) => $v !== '' && (float) $v > 0 ? round((float) $v, 2) : null;

        $reviewed = [
            'about' => $text($this->about),
            'landmark' => $text($this->landmark),
            'address_line' => trim($this->addressLine),
            'lat' => $this->lat !== '' ? round((float) $this->lat, 7) : null,
            'lng' => $this->lng !== '' ? round((float) $this->lng, 7) : null,
            'photos' => array_values($photos),
        ] + ($cover ? ['cover_image' => $cover] : []);
        $now = [
            'title' => VehicleListing::makeTitle($this->kind, $text($this->make), $text($this->model), $zone?->name),
            'vehicle_kind' => $this->kind,
            'make' => $text($this->make),
            'model' => $text($this->model),
            'year' => $this->year !== '' ? (int) $this->year : null,
            'fuel' => $this->fuel,
            'transmission' => $this->transmission ?: null,
            'seats' => $this->seats !== '' ? (int) $this->seats : null,
            'driver_options' => array_values(array_unique($this->driverOptions)),
            'price_day' => round((float) $this->priceDay, 2),
            'price_hour' => $number($this->priceHour),
            'deposit' => $number($this->deposit),
            'km_per_day' => $this->kmPerDay !== '' && (int) $this->kmPerDay > 0 ? (int) $this->kmPerDay : null,
            'zone_id' => $zone?->id,
            'features' => array_values(array_unique($this->features)) ?: null,
            'rule_list' => array_values(array_unique($this->ruleList)) ?: null,
        ];

        $waiting = false;
        $vehicle = DB::transaction(function () use ($existing, $partner, $reviewed, $now, &$waiting) {
            if ($existing === null) {
                return VehicleListing::create($reviewed + $now + [
                    'provider_id' => $partner->id,
                    'franchise_id' => $this->franchiseId(),
                    'slug' => VehicleListing::uniqueSlug($now['title']),
                    'contact_phone' => $partner->user?->phone,
                    'status' => 'draft',
                    'is_active' => false,
                ]);
            }
            if ($existing->status === 'approved') {
                // The public link stays the same on a live vehicle (old links never break).
                $existing->update($now);
                $wait = [];
                foreach ($reviewed as $field => $value) {
                    if (! self::same($existing->getAttribute($field), $value)) {
                        $wait[$field] = $value;
                    }
                }
                if ($wait !== [] || $existing->pending_changes !== null) {
                    $existing->update(['pending_changes' => $wait ?: null, 'pending_requested_at' => $wait ? now() : null, 'pending_rejection' => null]);
                    $waiting = $wait !== [];
                }

                return $existing;
            }
            $fields = $reviewed + $now;
            if ($existing->title !== $fields['title']) {
                $fields['slug'] = VehicleListing::uniqueSlug($fields['title'], $existing->id);
            }
            $existing->update($fields);

            return $existing;
        });

        $this->reset('cover', 'newPhotos');
        ActivityLogger::logModel(auth()->user(), $vehicle, $existing ? 'Vehicle for rent updated (Rental portal)' : 'Vehicle for rent added (Rental portal)', $waiting ? ['waiting_for_review' => array_keys((array) $vehicle->pending_changes)] : []);
        $this->listingId = $vehicle->id;
        $this->mount($vehicle->id);
        $this->notice = $waiting ? 'Saved. Price and details changed now; new photos, description or address show once we check them.' : 'Saved.';

        return $vehicle;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return array_values((array) ($a ?? [])) === array_values((array) ($b ?? []));
        }
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.0000001;
        }

        return (string) ($a ?? '') === (string) ($b ?? '');
    }

    public function submit(): void
    {
        $vehicle = $this->save();
        if ($vehicle === null || ! $vehicle->isEditable()) {
            return;
        }
        $problems = self::submitProblems($vehicle->refresh());
        if ($problems !== []) {
            $this->addError('vehicle', implode(' ', $problems));
            $this->notice = 'Saved as a draft.';

            return;
        }
        $vehicle->update(['status' => 'in_review', 'submitted_at' => now(), 'rejection_reason' => null]);
        ActivityLogger::logModel(auth()->user(), $vehicle, 'Vehicle for rent sent for review (Rental portal)');
        $this->notice = 'Sent for review. We check it and tell you here, usually within a day.';
    }

    /** @return list<string> what stops this vehicle from being sent for review / approved */
    public static function submitProblems(VehicleListing $vehicle): array
    {
        $problems = [];
        if (! filled($vehicle->vehicle_kind) || ! filled($vehicle->fuel) || empty($vehicle->driver_options)) {
            $problems[] = 'Tap the kind of vehicle, its fuel and how you rent it.';
        }
        if (! $vehicle->price_day || (float) $vehicle->price_day <= 0) {
            $problems[] = 'Enter the price per day.';
        }
        if (! filled($vehicle->address_line) || ! $vehicle->zone_id) {
            $problems[] = 'Tap the area and enter the pickup address.';
        }
        $min = (int) VehicleListingSettings::get('vehicles.min_photos', $vehicle->scope());
        if ($vehicle->cover_image === null) {
            $problems[] = 'Add a main photo.';
        }
        if (count((array) $vehicle->photos) < $min) {
            $problems[] = "Add at least {$min} more photos (front, side, inside).";
        }

        return $problems;
    }

    /** Approved: hide it for now ("Not available now") or show it again ("Available again"). */
    public function notAvailable(): void
    {
        $vehicle = $this->vehicle();
        if ($vehicle?->status !== 'approved' || ! $vehicle->is_active) {
            return;
        }
        $vehicle->update(['is_active' => false]);
        ActivityLogger::logModel(auth()->user(), $vehicle, 'Vehicle hidden: not available now (Rental portal)');
        $this->notice = 'Hidden. Customers no longer see it. Tap "Available again" when it is free.';
    }

    public function availableAgain(): void
    {
        $vehicle = $this->vehicle();
        if ($vehicle?->status !== 'approved') {
            return;
        }
        // Listing fee: where a fee applies, a period that has run out is renewed by paying, not by a tap.
        if (ListingFeeService::required($vehicle) && ! ListingFeeService::hasValidPeriod($vehicle)) {
            $this->addError('vehicle', 'Pay the listing fee below to show your vehicle again.');

            return;
        }
        $renew = ! ListingFeeService::required($vehicle) && ! ListingFeeService::hasValidPeriod($vehicle);
        $vehicle->update(['is_active' => true] + ($renew ? ['expires_at' => now()->addDays((int) VehicleListingSettings::get('vehicles.listing_days', $vehicle->scope()))] : []));
        ActivityLogger::logModel(auth()->user(), $vehicle, 'Vehicle shown again: available (Rental portal)');
        $this->notice = 'Customers see your vehicle again until '.$vehicle->expires_at->format('j M Y').'.';
    }

    /** Pay the listing fee or the feature fee, from the wallet ("wallet") or online ("online"). */
    public function payFee(string $kind, string $method): void
    {
        $vehicle = $this->vehicle();
        if ($vehicle === null || ! in_array($method, ['wallet', 'online'], true)) {
            return;
        }
        $fees = app(ListingFeeService::class);
        try {
            if ($method === 'wallet') {
                $fees->payFromWallet(auth()->user(), $vehicle, $kind);
                $this->notice = $kind === 'featured' ? 'Done. Your vehicle is shown first for now.' : 'Paid. Customers see your vehicle until '.$vehicle->fresh()->expires_at->format('j M Y').'.';
            } else {
                $this->dispatch('razorpay-open', order: $fees->startOnline(auth()->user(), $vehicle, $kind))->self();
            }
        } catch (\RuntimeException $e) {
            $this->addError('vehicle', $e->getMessage());
        }
    }

    public function withdrawChanges(): void
    {
        $vehicle = $this->vehicle();
        if ($vehicle === null || $vehicle->pending_changes === null) {
            return;
        }
        $vehicle->update(['pending_changes' => null, 'pending_requested_at' => null]);
        ActivityLogger::logModel(auth()->user(), $vehicle, 'Waiting vehicle change withdrawn (Rental portal)');
        $this->mount($vehicle->id);
        $this->notice = 'Change withdrawn.';
    }

    public function render()
    {
        $vehicle = $this->vehicle();
        $scope = $this->scope();

        return view('livewire.venue.vehicle-editor', [
            'vehicle' => $vehicle,
            'editable' => $vehicle === null || $vehicle->status !== 'in_review',
            'kinds' => $this->choices('vehicles.kinds', [$this->kind]),
            'fuels' => $this->choices('vehicles.fuels', [$this->fuel]),
            'gears' => $this->choices('vehicles.transmissions', [$this->transmission]),
            'driverChoices' => $this->choices('vehicles.driver_options', $this->driverOptions),
            'featureChoices' => $this->choices('vehicles.features', $this->features),
            'ruleChoices' => $this->choices('vehicles.rules', $this->ruleList),
            'seatChoices' => self::SEAT_CHOICES,
            'zones' => Zone::where('franchise_id', $this->franchiseId())->orderBy('name')->get(['id', 'name']),
            'minPhotos' => (int) VehicleListingSettings::get('vehicles.min_photos', $scope),
            'symbol' => \App\Models\Setting::get('locale.currency_symbol', '₹'),
            'fee' => $vehicle && $vehicle->status === 'approved' ? [
                'on' => ListingFeeService::required($vehicle),
                'listing' => ListingFeeService::amount($vehicle, 'listing'),
                'listingDays' => ListingFeeService::days($vehicle, 'listing'),
                'featured' => ListingFeeService::amount($vehicle, 'featured'),
                'featuredDays' => ListingFeeService::days($vehicle, 'featured'),
                'wallet' => app(WalletService::class)->balance(auth()->user()),
                'online' => app(ListingFeeService::class)->onlineAvailable($vehicle),
            ] : null,
        ])->layout('components.layouts.venue', ['title' => $vehicle ? $vehicle->title : 'Add a vehicle for rent']);
    }
}
